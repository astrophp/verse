<?php

declare(strict_types=1);

namespace Astro\Verse\Build;

use Astro\Verse\Error\FilesystemException;
use Astro\Verse\Support\Warnings;
use Astro\Verse\Verse;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * Publishes a staged build by two renames (output to `previous`, staging to output) behind a journal, and
 * recovers an interrupted promotion.
 *
 * All state lives in `.verse/`: `staging/<id>/`, `previous/<id>/` and `transactions/<sha1(output)>.json`
 * (`{id, output, previous, staging}`). The caller holds the build lock for the whole life of the object.
 */
final class OutputTransaction
{
    public const string MARKER = '.verse-output';

    private const string ID_PATTERN = '/^\d{8}-\d{6}-[0-9a-f]{8}$/';

    private readonly string $state;

    public function __construct(
        private readonly string $root,
        private readonly string $output,
        private readonly Filesystem $fs,
    ) {
        $this->state = $root.'/.verse';
    }

    public static function newId(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone('UTC'))->format('Ymd-His').'-'.bin2hex(random_bytes(4));
    }

    public function stagingPath(string $id): string
    {
        return $this->state.'/staging/'.$id;
    }

    public function previousPath(string $id): string
    {
        return $this->state.'/previous/'.$id;
    }

    public function markerContents(string $id): string
    {
        return json_encode(['id' => $id, 'verse' => Verse::VERSION], JSON_THROW_ON_ERROR)."\n";
    }

    public function journalPath(): string
    {
        return $this->state.'/transactions/'.sha1($this->output).'.json';
    }

    /**
     * Refuses to replace a directory Verse did not create: the output must be absent, or a real
     * directory with a parsable marker.
     */
    public function assertManaged(): void
    {
        if (! file_exists($this->output) && ! is_link($this->output)) {
            return;
        }
        if (is_link($this->output) || ! is_dir($this->output)) {
            throw new FilesystemException('the output path '.$this->output.' is a symbolic link or not a directory. Move it away or choose another output.');
        }
        if (self::markerId($this->output) === null) {
            throw new FilesystemException(
                $this->output.' was not created by Verse (it has no valid '.self::MARKER.' marker), so it will not be replaced. Move it away, or build into another folder.',
            );
        }
    }

    /**
     * Deletes staging directories left by builds that never reached promotion.
     */
    public function clearStaging(): void
    {
        $this->fs->removeTree($this->state.'/staging');
    }

    /**
     * Completes or rolls back every interrupted promotion. Journals are all
     * validated before anything is touched.
     */
    public function recover(Warnings $warnings): void
    {
        $journals = glob($this->state.'/transactions/*.json') ?: [];
        sort($journals, SORT_STRING);

        /** @var list<array{file: string, id: string, output: string, previous: string, staging: string}> $valid */
        $valid = [];
        foreach ($journals as $file) {
            $valid[] = $this->readJournal($file);
        }

        foreach ($valid as $journal) {
            $this->recoverOne($journal, $warnings);
        }
    }

    /**
     * Moves the staged tree into place. Either the new output is in place afterwards, or the previous one is
     * (an exception reports why); the journal outlives only a failed restoration.
     *
     * @throws FilesystemException
     */
    public function promote(string $id): void
    {
        $this->assertManaged();

        $staging = $this->stagingPath($id);
        $previous = $this->previousPath($id);
        $journal = $this->journalPath();

        $this->fs->mkdir(dirname($journal));
        $this->writeJournal($journal, $id, $previous, $staging);

        $hadOutput = is_dir($this->output);
        $this->fs->mkdir(dirname($this->output));

        if ($hadOutput) {
            $this->fs->mkdir(dirname($previous));
            try {
                $this->fs->rename($this->output, $previous);
            } catch (FilesystemException $exception) {
                $this->fs->removeTree($journal);
                $this->fs->removeTree($staging);

                throw new FilesystemException('could not set the previous output aside; nothing was changed. '.$exception->detail, previous: $exception);
            }
        }

        try {
            $this->fs->rename($staging, $this->output);
        } catch (FilesystemException $exception) {
            $restoreFailure = null;
            if ($hadOutput) {
                try {
                    $this->fs->rename($previous, $this->output);
                } catch (FilesystemException $failure) {
                    $restoreFailure = $failure;
                }
            }

            if ($restoreFailure === null) {
                $this->fs->removeTree($journal);
                $this->fs->removeTree($staging);

                throw new FilesystemException(
                    'could not publish the new output'.($hadOutput ? '; the previous output was restored' : '').'. '.$exception->detail,
                    previous: $exception,
                );
            }

            throw new FilesystemException(
                'could not publish the new output ('.$exception->detail.') and could not restore the previous one ('
                .$restoreFailure->detail.'). The previous output is in '.$previous
                .'; the next build restores it from the journal '.$journal.'.',
                previous: $exception,
            );
        }

        $this->fs->removeTree($previous);
        $this->fs->removeTree($journal);
    }

    /**
     * @return array{file: string, id: string, output: string, previous: string, staging: string}
     */
    private function readJournal(string $file): array
    {
        $refuse = fn (string $why): FilesystemException => new FilesystemException(
            'the transaction journal '.$file.' is not valid ('.$why.'); nothing was deleted. Inspect the journal and the folders it names, then delete it.',
        );

        $text = file_get_contents($file);
        try {
            $data = $text === false ? null : json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $refuse('not valid JSON');
        }
        if (! is_array($data)) {
            throw $refuse('not a JSON object');
        }

        $id = $data['id'] ?? null;
        $output = $data['output'] ?? null;
        $previous = $data['previous'] ?? null;
        $staging = $data['staging'] ?? null;
        if (! is_string($id) || ! is_string($output) || ! is_string($previous) || ! is_string($staging)) {
            throw $refuse('id, output, previous and staging must be strings');
        }
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw $refuse('malformed id');
        }
        if (basename($file) !== sha1($output).'.json') {
            throw $refuse('the file name does not match its output');
        }
        if ($previous !== $this->previousPath($id) || $staging !== $this->stagingPath($id)) {
            throw $refuse('previous and staging must be .verse/previous/'.$id.' and .verse/staging/'.$id);
        }
        if (! $this->isExpectedOutput($output)) {
            throw $refuse('the output path '.$output.' is not an output of this site');
        }

        return ['file' => $file, 'id' => $id, 'output' => $output, 'previous' => $previous, 'staging' => $staging];
    }

    private function isExpectedOutput(string $output): bool
    {
        if (! str_starts_with($output, '/') || in_array('..', explode('/', $output), true) || in_array('.', explode('/', $output), true)) {
            return false;
        }

        return $output === $this->output
            || $output === $this->root.'/dist'
            || (PathGuard::inside($output, $this->state.'/preview') && $output !== $this->state.'/preview');
    }

    /**
     * @param  array{file: string, id: string, output: string, previous: string, staging: string}  $journal
     */
    private function recoverOne(array $journal, Warnings $warnings): void
    {
        ['file' => $file, 'id' => $id, 'output' => $output, 'previous' => $previous, 'staging' => $staging] = $journal;
        $label = 'recovered an interrupted build of '.$output;

        $outputExists = is_dir($output) && ! is_link($output);
        $previousExists = is_dir($previous);

        if ($outputExists && self::markerId($output) === $id) {
            $this->fs->removeTree($previous);
            $warnings->add($label.': the new output was already in place; removed the old copy.');
        } elseif ($outputExists || is_link($output) || file_exists($output)) {
            $warnings->add($label.': the new output was never put in place; the existing output was kept'
                .($previousExists ? ' (a leftover copy remains in '.$previous.'; delete it if you do not need it)' : '').'.');
        } elseif ($previousExists) {
            try {
                $this->fs->mkdir(dirname($output));
                $this->fs->rename($previous, $output);
            } catch (FilesystemException $exception) {
                throw new FilesystemException(
                    'could not restore the previous output of an interrupted build ('.$exception->detail.'). It is still in '.$previous
                    .'; move it to '.$output.' yourself, then delete the journal '.$file.'.',
                    previous: $exception,
                );
            }
            $warnings->add($label.': restored the previous output.');
        } else {
            $warnings->add($label.': there was no previous output to restore.');
        }

        $this->fs->removeTree($staging);
        $this->fs->removeTree($file);
    }

    private function writeJournal(string $journal, string $id, string $previous, string $staging): void
    {
        $contents = json_encode(
            ['id' => $id, 'output' => $this->output, 'previous' => $previous, 'staging' => $staging],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";

        // The temporary file is created exclusively, so a link planted at its name is never followed; a stale
        // file or link is removed (the link itself, not its target) and creation is retried once.
        $temporary = $journal.'.tmp';
        try {
            $this->fs->writeFile($temporary, $contents, sync: true, exclusive: true);
        } catch (FilesystemException $exception) {
            if (! is_link($temporary) && ! is_file($temporary)) {
                throw $exception;
            }
            $this->fs->removeTree($temporary);
            $this->fs->writeFile($temporary, $contents, sync: true, exclusive: true);
        }
        $this->fs->rename($temporary, $journal);
    }

    /**
     * The `id` of an output's marker, or null when the marker is missing or not parsable.
     */
    public static function markerId(string $directory): ?string
    {
        $text = @file_get_contents($directory.'/'.self::MARKER);
        if ($text === false) {
            return null;
        }
        try {
            $data = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) && is_string($data['id'] ?? null) ? $data['id'] : null;
    }
}
