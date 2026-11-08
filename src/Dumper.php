<?php

declare(strict_types=1);

namespace Jtech\Dumper;

use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;

class Dumper
{
    private static int $dumpIdCount = 0;
    private static ?float $startTime = null;

    /**
     * Inspect & dump variables with execution metadata and interactive UI.
     */
    public static function dump(mixed ...$vars): void
    {
        if (self::$startTime === null) {
            self::$startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        }

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $backtrace[0] ?? ['file' => 'unknown', 'line' => 0];

        $isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';

        foreach ($vars as $index => $var) {
            self::$dumpIdCount++;
            if ($isCli) {
                self::renderCli($var, $caller, $index + 1, count($vars));
            } else {
                self::renderHtml($var, $caller, self::$dumpIdCount);
            }
        }
    }

    /**
     * Dump variables and terminate execution.
     */
    public static function dd(mixed ...$vars): never
    {
        self::dump(...$vars);
        exit(1);
    }

    private static function renderHtml(mixed $var, array $caller, int $dumpId): void
    {
        $id = 'sd_dump_' . $dumpId . '_' . uniqid();
        $memoryUsage = self::formatBytes(memory_get_usage(true));
        $peakMemory = self::formatBytes(memory_get_peak_usage(true));
        $executionTime = number_format((microtime(true) - self::$startTime) * 1000, 2) . ' ms';
        $fileLine = htmlspecialchars(($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? 0));
        $processedObjects = [];

        $htmlTree = self::buildHtmlValue($var, $processedObjects);

        echo <<<HTML
        <div id="{$id}" class="sd-container">
            <style>
                .sd-container {
                    background-color: #0d1117;
                    color: #c9d1d9;
                    font-family: ui-monospace, SFMono-Regular, SF Mono, Menlo, Consolas, Liberation Mono, monospace;
                    font-size: 13px;
                    line-height: 1.5;
                    border: 1px solid #30363d;
                    border-radius: 8px;
                    margin: 16px 0;
                    padding: 0;
                    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
                    overflow: hidden;
                    text-align: left;
                    z-index: 99999;
                    position: relative;
                }
                .sd-header {
                    background: #161b22;
                    border-bottom: 1px solid #30363d;
                    padding: 8px 14px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    flex-wrap: wrap;
                    gap: 8px;
                }
                .sd-meta {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    font-size: 11px;
                }
                .sd-badge {
                    background: #21262d;
                    color: #8b949e;
                    padding: 2px 8px;
                    border-radius: 12px;
                    border: 1px solid #30363d;
                }
                .sd-badge-highlight {
                    color: #58a6ff;
                    border-color: rgba(88, 166, 255, 0.3);
                }
                .sd-file {
                    color: #79c0ff;
                    font-weight: 600;
                    word-break: break-all;
                }
                .sd-toolbar {
                    display: flex;
                    gap: 6px;
                    align-items: center;
                }
                .sd-search {
                    background: #0d1117;
                    border: 1px solid #30363d;
                    color: #c9d1d9;
                    padding: 3px 8px;
                    border-radius: 4px;
                    font-size: 11px;
                    outline: none;
                }
                .sd-search:focus { border-color: #58a6ff; }
                .sd-btn {
                    background: #21262d;
                    border: 1px solid #30363d;
                    color: #c9d1d9;
                    padding: 3px 8px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 11px;
                    transition: all 0.2s;
                }
                .sd-btn:hover { background: #30363d; color: #fff; }
                .sd-body { padding: 12px 16px; overflow-x: auto; max-height: 600px; overflow-y: auto; }
                .sd-tree { list-style: none; margin: 0; padding: 0; }
                .sd-tree ul { list-style: none; margin: 0; padding-left: 18px; border-left: 1px solid #21262d; }
                .sd-node { margin: 2px 0; }
                .sd-key { color: #79c0ff; font-weight: 600; }
                .sd-type-string { color: #a5d6ff; }
                .sd-type-int, .sd-type-float { color: #79c0ff; }
                .sd-type-bool { color: #ff7b72; font-weight: bold; }
                .sd-type-null { color: #8b949e; font-style: italic; }
                .sd-type-object { color: #d2a8ff; font-weight: 600; }
                .sd-type-array { color: #ffa657; font-weight: 600; }
                .sd-type-resource { color: #f2cc60; }
                .sd-vis-public { color: #7ee787; font-size: 10px; margin-right: 4px; }
                .sd-vis-protected { color: #ffa657; font-size: 10px; margin-right: 4px; }
                .sd-vis-private { color: #ff7b72; font-size: 10px; margin-right: 4px; }
                details summary { cursor: pointer; user-select: none; outline: none; }
                details summary::-webkit-details-marker { color: #8b949e; }
                .sd-copy-btn {
                    opacity: 0;
                    margin-left: 6px;
                    background: none;
                    border: none;
                    color: #8b949e;
                    cursor: pointer;
                    font-size: 10px;
                }
                .sd-node:hover > .sd-copy-btn, summary:hover > .sd-copy-btn { opacity: 1; }
                .sd-hidden { display: none !important; }
            </style>

            <div class="sd-header">
                <div class="sd-meta">
                    <span class="sd-file">📍 {$fileLine}</span>
                    <span class="sd-badge sd-badge-highlight">⏱️ {$executionTime}</span>
                    <span class="sd-badge">💾 Mem: {$memoryUsage} (Peak: {$peakMemory})</span>
                </div>
                <div class="sd-toolbar">
                    <input type="text" class="sd-search" placeholder="Cari key/value..." onkeyup="SmartDumper.search('{$id}', this.value)">
                    <button class="sd-btn" onclick="SmartDumper.toggleAll('{$id}', true)">Expand All</button>
                    <button class="sd-btn" onclick="SmartDumper.toggleAll('{$id}', false)">Collapse All</button>
                </div>
            </div>

            <div class="sd-body">
                <ul class="sd-tree">
                    <li class="sd-node">{$htmlTree}</li>
                </ul>
            </div>
        </div>

        <script>
        if (typeof SmartDumper === 'undefined') {
            window.SmartDumper = {
                toggleAll: function(containerId, expand) {
                    const container = document.getElementById(containerId);
                    if (!container) return;
                    const details = container.querySelectorAll('details');
                    details.forEach(d => d.open = expand);
                },
                search: function(containerId, query) {
                    const container = document.getElementById(containerId);
                    if (!container) return;
                    const nodes = container.querySelectorAll('.sd-node');
                    const q = query.toLowerCase().trim();
                    nodes.forEach(node => {
                        if (!q) {
                            node.classList.remove('sd-hidden');
                            return;
                        }
                        const text = node.innerText.toLowerCase();
                        if (text.includes(q)) {
                            node.classList.remove('sd-hidden');
                            let parent = node.parentElement;
                            while (parent && parent !== container) {
                                if (parent.tagName === 'DETAILS') parent.open = true;
                                parent = parent.parentElement;
                            }
                        } else {
                            node.classList.add('sd-hidden');
                        }
                    });
                },
                copy: function(btn, text) {
                    navigator.clipboard.writeText(text).then(() => {
                        const original = btn.innerText;
                        btn.innerText = 'Copied!';
                        setTimeout(() => btn.innerText = original, 1200);
                    });
                }
            };
        }
        </script>
        HTML;
    }

    private static function buildHtmlValue(mixed $var, array &$processedObjects): string
    {
        $type = gettype($var);

        return match ($type) {
            'string' => self::renderStringHtml($var),
            'integer', 'double' => "<span class=\"sd-type-int\">{$type}</span> <strong style=\"color:#a5d6ff;\">{$var}</strong>"
                . self::copyButton((string)$var),
            'boolean' => "<span class=\"sd-type-bool\">" . ($var ? 'true' : 'false') . "</span>",
            'NULL' => "<span class=\"sd-type-null\">null</span>",
            'array' => self::renderArrayHtml($var, $processedObjects),
            'object' => self::renderObjectHtml($var, $processedObjects),
            'resource' => "<span class=\"sd-type-resource\">resource(" . get_resource_type($var) . ")</span>",
            default => htmlspecialchars((string)$var),
        };
    }

    private static function renderStringHtml(string $str): string
    {
        $len = mb_strlen($str);
        $escaped = htmlspecialchars($str);
        $preview = $len > 100 ? htmlspecialchars(mb_substr($str, 0, 100)) . '...' : $escaped;

        if ($len > 100) {
            return "<details style=\"display:inline;\">
                <summary><span class=\"sd-type-string\">string({$len})</span> \"{$preview}\"</summary>
                <div style=\"background:#161b22; padding:8px; border-radius:4px; margin-top:4px; white-space:pre-wrap; word-break:break-all;\">\"{$escaped}\"</div>
            </details>" . self::copyButton($str);
        }

        return "<span class=\"sd-type-string\">string({$len})</span> \"{$escaped}\"" . self::copyButton($str);
    }

    private static function renderArrayHtml(array $arr, array &$processedObjects): string
    {
        $count = count($arr);
        if ($count === 0) {
            return "<span class=\"sd-type-array\">array:0</span> []";
        }

        $html = "<details open><summary><span class=\"sd-type-array\">array:{$count}</span> [</summary><ul>";
        foreach ($arr as $key => $val) {
            $formattedKey = is_string($key) ? "\"<span class=\"sd-key\">{$key}</span>\"" : "<span class=\"sd-key\">{$key}</span>";
            $valHtml = self::buildHtmlValue($val, $processedObjects);
            $html .= "<li class=\"sd-node\">{$formattedKey} => {$valHtml}</li>";
        }
        $html .= "</ul>]</details>";

        return $html;
    }

    private static function renderObjectHtml(object $obj, array &$processedObjects): string
    {
        $hash = spl_object_hash($obj);
        $className = get_class($obj);

        if (isset($processedObjects[$hash])) {
            return "<span class=\"sd-type-object\">class {$className}</span> <span style=\"color:#ff7b72;\">#RECURSION CIRCULAR#</span>";
        }
        $processedObjects[$hash] = true;

        $ref = new ReflectionClass($obj);
        $properties = $ref->getProperties();
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        $html = "<details><summary><span class=\"sd-type-object\">class {$className}</span> <span style=\"color:#8b949e;\">#" . spl_object_id($obj) . "</span></summary><ul>";

        // Properties Section
        if (!empty($properties)) {
            $html .= "<li class=\"sd-node\"><strong>Properties (" . count($properties) . "):</strong><ul>";
            foreach ($properties as $prop) {
                $prop->setAccessible(true);
                $vis = $prop->isPrivate() ? '<span class="sd-vis-private">[private]</span>' :
                       ($prop->isProtected() ? '<span class="sd-vis-protected">[protected]</span>' : '<span class="sd-vis-public">[public]</span>');
                $isInitialized = $prop->isInitialized($obj);
                $valHtml = $isInitialized ? self::buildHtmlValue($prop->getValue($obj), $processedObjects) : '<span class="sd-type-null">uninitialized</span>';
                
                $html .= "<li class=\"sd-node\">{$vis}<span class=\"sd-key\">{$prop->getName()}</span>: {$valHtml}</li>";
            }
            $html .= "</ul></li>";
        }

        // Methods Section
        if (!empty($methods)) {
            $html .= "<li class=\"sd-node\"><details><summary><strong style=\"color:#8b949e;\">Public Methods (" . count($methods) . ")</strong></summary><ul>";
            foreach ($methods as $method) {
                $params = array_map(fn($p) => ($p->hasType() ? $p->getType() . ' ' : '') . '$' . $p->getName(), $method->getParameters());
                $paramStr = htmlspecialchars(implode(', ', $params));
                $html .= "<li class=\"sd-node\" style=\"color:#8b949e;\">function <span style=\"color:#d2a8ff;\">{$method->getName()}</span>({$paramStr})</li>";
            }
            $html .= "</ul></details></li>";
        }

        $html .= "</ul></details>";
        return $html;
    }

    private static function copyButton(string $text): string
    {
        $safeText = htmlspecialchars($text, ENT_QUOTES);
        return "<button class=\"sd-copy-btn\" onclick=\"SmartDumper.copy(this, '{$safeText}')\" title=\"Copy value\">📋</button>";
    }

    private static function renderCli(mixed $var, array $caller, int $index, int $total): void
    {
        $fileLine = ($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? 0);
        $mem = self::formatBytes(memory_get_usage(true));
        
        echo "\033[1;34m[SmartDump {$index}/{$total}]\033[0m \033[0;36m{$fileLine}\033[0m \033[0;33m({$mem})\033[0m\n";
        echo self::formatCliValue($var, 0) . "\n\n";
    }

    private static function formatCliValue(mixed $var, int $indent = 0): string
    {
        $pad = str_repeat('  ', $indent);
        $type = gettype($var);

        return match ($type) {
            'string' => "{$pad}\033[0;32m\"{$var}\"\033[0m (string," . strlen($var) . ")",
            'integer', 'double' => "{$pad}\033[1;36m{$var}\033[0m ({$type})",
            'boolean' => "{$pad}\033[1;31m" . ($var ? 'true' : 'false') . "\033[0m (bool)",
            'NULL' => "{$pad}\033[2mnull\033[0m",
            'array' => self::formatCliArray($var, $indent),
            'object' => "{$pad}\033[1;35mclass " . get_class($var) . "\033[0m #" . spl_object_id($var),
            default => "{$pad}" . print_r($var, true),
        };
    }

    private static function formatCliArray(array $arr, int $indent): string
    {
        $pad = str_repeat('  ', $indent);
        if (empty($arr)) return "{$pad}array:0 []";
        
        $out = "{$pad}array:" . count($arr) . " [\n";
        foreach ($arr as $k => $v) {
            $keyStr = is_string($k) ? "\"{$k}\"" : $k;
            $out .= "{$pad}  \033[1;34m{$keyStr}\033[0m => " . ltrim(self::formatCliValue($v, $indent + 1)) . "\n";
        }
        $out .= "{$pad}]";
        return $out;
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min((int)$pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

