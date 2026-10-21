---
title: The bug hiding in a successful trace
description: Fixture article. A successful run can still be wrong, and a trace shows why.
date: 2026-10-10
updated: 2026-10-12
tags: [AI systems, Observability]
permalink: /essays/trace/
image: diagram.svg
---
> [!NOTE] Fixture text
> This article is fixture text for the Verse test suite. It is not an authentic article.

## A successful run can still be wrong

The request finished. The worker stayed alive. The agent returned a fluent answer. From the outside, every signal suggested that the system had done its job.[^done]

But finishing is only one part of correctness. An answer can rely on the wrong record, skip a necessary check, or quietly inherit a mistake from an earlier step. A successful execution tells us that the machinery completed; it does not tell us whether the conclusion was justified.

> The useful question is not only “Did the run succeed?” It is also “What made this answer possible?”

## Follow the evidence through the run

Consider a hypothetical property assistant asked to explain why a listing price changed. It retrieves a listing, reads a price history, and writes a summary. Each operation may complete correctly while the relationship between them is wrong.

![Illustrative execution tree](diagram.svg "An illustrative execution tree of an agent run.")

### Inspect the joins between steps

| Stage | Question to ask |
| --- | --- |
| Selection | Which listing did the agent resolve? |
| Retrieval | Does the history belong to that listing? |
| Response | Which evidence supports the explanation? |

The defect may live between two individually reasonable actions. Looking only at the last response makes that relationship difficult to see.

## Reconstruct the decision, not just the error

A useful trace preserves enough structure to connect the request, selected subject, tool inputs, and returned evidence.

```text
run = begin_trace(request)
subject = resolve_subject(request)
evidence = fetch_history(subject.id)

if evidence.subject_id != subject.id:
    stop_with("Evidence belongs to another subject")

record_link(run, subject, evidence)
return explain(evidence)
```

1. Identify the subject the request actually refers to.
2. Check which evidence entered the run.
3. Locate the first point where the intended relationship changed.

```php
<?php echo $x; ?>
$trace = Trace::begin($request);
```

```blade
{{ $x }}
@if ($show) <x-foo/> @endif
```

## Make the failure harder to repeat

Once the mismatch is understood, the repair belongs at the boundary that allowed it. Read [the older note](../older-note.md).

> [!NOTE] Keep the trace purposeful
> Record the evidence needed to explain behavior. Decide separately which payloads can be retained.

[^done]: A footnote in fixture text.
