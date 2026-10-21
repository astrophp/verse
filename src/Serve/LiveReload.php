<?php

declare(strict_types=1);

namespace Astro\Verse\Serve;

/**
 * The inline script `verse serve` adds to every HTML page it serves. It carries the generation of the artifact
 * the page came from and polls `/__verse/status` (always at the server root, never under the base path):
 * a different generation reloads the page, a build error shows a dismissible overlay and the page stays.
 */
final class LiveReload
{
    public const string STATUS_PATH = '/__verse/status';

    private const string SCRIPT = <<<'JS'
(function(){
var g=%GENERATION%,d=null,k='verse-reload-overlay';
function show(e){
var o=document.getElementById(k),m=String(e.message||'The build failed.'),f=e.file?String(e.file):'',s=m+'|'+f;
if(d===s)return;
if(!o){o=document.createElement('div');o.id=k;o.setAttribute('role','alert');o.style.cssText='position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483647;max-height:50vh;overflow:auto;padding:12px 16px;border-radius:8px;background:#2b0f0f;color:#ffe3e3;border:1px solid #c0392b;font:13px/1.5 ui-monospace,Menlo,Consolas,monospace;box-shadow:0 8px 32px rgba(0,0,0,.4)';document.body.appendChild(o)}
o.textContent='';
var b=document.createElement('button');b.type='button';b.textContent='Close';b.style.cssText='float:right;margin-left:12px;cursor:pointer';b.onclick=function(){d=s;o.remove()};
var t=document.createElement('div');t.style.cssText='white-space:pre-wrap;word-break:break-word';t.textContent=m+(f?'\n'+f:'');
o.appendChild(b);o.appendChild(t)}
function hide(){var o=document.getElementById(k);if(o)o.remove();d=null}
function poll(){fetch(%STATUS%,{cache:'no-store'}).then(function(r){return r.json()}).then(function(s){
if(s.result==='error')show(s.error||{});
else if(s.result==='ok'){if(s.generation!==g)location.reload();else hide()}
}).catch(function(){})}
setInterval(poll,1000)
})();
JS;

    public static function script(?string $generation): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $body = strtr(self::SCRIPT, [
            '%GENERATION%' => json_encode($generation, $flags),
            '%STATUS%' => json_encode(self::STATUS_PATH, $flags),
        ]);

        return '<script data-verse-live-reload>'.$body.'</script>';
    }

    /**
     * Adds the script before the last `</body>` (at the end when there is none).
     */
    public static function inject(string $html, ?string $generation): string
    {
        $script = self::script($generation);
        $position = strripos($html, '</body>');

        return $position === false
            ? $html.$script
            : substr($html, 0, $position).$script.substr($html, $position);
    }
}
