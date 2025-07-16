<?php

namespace Spark\View;

use InvalidArgumentException;
use Spark\View\Contracts\BladeCompilerContract;

/**
 * Class BladeCompiler
 * 
 * Compiles Blade-like templates to PHP
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class BladeCompiler implements BladeCompilerContract
{
    /**
     * Path to store compiled templates
     * 
     * @var string
     */
    private string $cachePath;

    /**
     * Array of custom directives
     * 
     * @var array
     */
    private array $customDirectives = [];

    /**
     * Raw PHP blocks to preserve during compilation
     * 
     * @var array
     */
    private array $rawBlocks = [];

    public function __construct(string $cachePath)
    {
        $this->cachePath = dir_path($cachePath);
        $this->ensureCacheDirectoryExists();
    }

    /**
     * Compile a template file
     * 
     * @param string $templatePath
     * @param string $compiledPath
     * @return void
     */
    public function compile(string $templatePath, string $compiledPath): void
    {
        $content = file_get_contents($templatePath);
        $compiled = $this->compileString($content);

        file_put_contents($compiledPath, $compiled);
    }

    /**
     * Compile template string
     * 
     * @param string $template
     * @return string
     */
    public function compileString(string $template): string
    {
        // Remove Blade comments first
        $template = $this->compileComments($template);

        // Preserve verbatim blocks (add this line)
        $template = $this->preserveVerbatimBlocks($template);

        // Preserve raw PHP blocks
        $template = $this->preserveRawBlocks($template);

        // Compile @use directive
        $template = $this->compileUseDirective($template);

        // Compile structural directives
        $template = $this->compileExtends($template);
        $template = $this->compileSections($template);
        $template = $this->compileYields($template);
        $template = $this->compileIncludes($template);
        $template = $this->compileXComponents($template);

        // Compile control flow directives
        $template = $this->compileDirectives($template);

        // Compile PHP blocks
        $template = $this->compilePhpBlocks($template);

        // Compile echo statements last to avoid conflicts
        $template = $this->compileEchos($template);

        // Restore raw PHP blocks
        $template = $this->restoreRawBlocks($template);

        return $template;
    }

    /**
     * Preserve verbatim blocks
     * 
     * @param string $template
     * @return string
     */
    private function preserveVerbatimBlocks(string $template): string
    {
        return preg_replace_callback('/\@verbatim(.*?)\@endverbatim/s', function ($matches) {
            $key = '__VERBATIM_BLOCK_' . count($this->rawBlocks) . '__';
            $content = $matches[1];
            $this->rawBlocks[$key] = $content; // Store as-is, no PHP tags
            return $key;
        }, $template);
    }

    /**
     * Preserve raw PHP blocks
     * 
     * @param string $template
     * @return string
     */
    private function preserveRawBlocks(string $template): string
    {
        return preg_replace_callback('/\@php(.*?)\@endphp/s', function ($matches) {
            $key = '__RAW_BLOCK_' . count($this->rawBlocks) . '__';
            $content = trim($matches[1]);
            $this->rawBlocks[$key] = "<?php" . ($content ? "\n    " . $content . "\n" : "") . "?>";
            return $key;
        }, $template);
    }

    /**
     * Restore raw PHP blocks
     * 
     * @param string $template
     * @return string
     */
    private function restoreRawBlocks(string $template): string
    {
        foreach ($this->rawBlocks as $key => $block) {
            $template = str_replace($key, $block, $template);
        }
        $this->rawBlocks = [];
        return $template;
    }

    /**
     * Compile @extends directive
     * 
     * @param string $template
     * @return string
     */
    private function compileExtends(string $template): string
    {
        return preg_replace('/\@extends\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '<?php $this->setExtends(\'$1\'); ?>', $template);
    }

    /**
     * Compile @section and @endsection directives
     */
    private function compileSections(string $template): string
    {
        // @section('name')
        $template = preg_replace('/\@section\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '<?php $this->startSection(\'$1\'); ?>', $template);

        // @section('name', expression) - inline section
        $template = preg_replace_callback('/\@section\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(.+?)\s*\)/s', function ($matches) {
            $sectionName = $matches[1];
            $expression = trim($matches[2]);
            return "<?php \$this->startSection('$sectionName'); echo $expression; \$this->endSection(); ?>";
        }, $template);

        // @endsection
        $template = preg_replace('/\@endsection/', '<?php $this->endSection(); ?>', $template);

        // @stop (alias for @endsection)
        $template = preg_replace('/\@stop/', '<?php $this->endSection(); ?>', $template);

        // @show (end section and immediately yield it)
        $template = preg_replace('/\@show/', '<?php $this->endSection(); echo $this->yieldSection($this->getCurrentSection()); ?>', $template);

        return $template;
    }

    /**
     * Compile @yield directives
     */
    private function compileYields(string $template): string
    {
        // @yield('section', 'default') - with string default
        $template = preg_replace('/\@yield\s*\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*[\'"]([^\'"]*)[\'"])?\s*\)/', '<?= $this->yieldSection(\'$1\', \'$2\'); ?>', $template);

        // @yield('section', $variable) - with variable default
        $template = preg_replace('/\@yield\s*\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*([^,\)\'\"]+))?\s*\)/', '<?= $this->yieldSection(\'$1\', isset($2) ? $2 : \'\'); ?>', $template);

        // @yield('section') - no default
        $template = preg_replace('/\@yield\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', '<?= $this->yieldSection(\'$1\', \'\'); ?>', $template);

        return $template;
    }

    /**
     * Compile X-Components (<x-component-name>)
     * 
     * @param string $template
     * @return string
     */
    private function compileXComponents(string $template): string
    {
        // Process components recursively from innermost to outermost
        $previousTemplate = '';
        while ($template !== $previousTemplate) {
            $previousTemplate = $template;

            // Handle self-closing x-components first: <x-component />
            $template = $this->compileSelfClosingXComponents($template);

            // Handle x-components with content: <x-component>content</x-component>
            $template = $this->compileXComponentsWithContent($template);
        }

        return $template;
    }

    /**
     * Compile self-closing x-components: <x-component />
     */
    private function compileSelfClosingXComponents(string $template): string
    {
        $pattern = '/<x-([a-zA-Z0-9\-_.]+)([^>]*?)\/>/';

        return preg_replace_callback($pattern, function ($matches) {
            $componentName = $matches[1];
            $attributesString = trim($matches[2]);

            // Parse attributes
            $attributes = $this->parseXComponentAttributes($attributesString);
            $attributesArray = $this->buildAttributesArray($attributes);

            return "<?= \$this->component('{$componentName}', {$attributesArray}); ?>";
        }, $template);
    }

    /**
     * Compile x-components with content: <x-component>content</x-component>
     */
    private function compileXComponentsWithContent(string $template): string
    {
        // First, find the innermost components (those without nested x-components in their content)
        $pattern = '/<x-([a-zA-Z0-9\-_.]+)([^>]*?)>((?:(?!<x-[a-zA-Z0-9\-_.]+|<\/x-[a-zA-Z0-9\-_.]+>).)*)<\/x-\1>/s';

        return preg_replace_callback($pattern, function ($matches) {
            $componentName = $matches[1];
            $attributesString = trim($matches[2]);
            $slotContent = $matches[3];

            // Parse attributes
            $attributes = $this->parseXComponentAttributes($attributesString);

            // Process slot content
            $processedSlotContent = $this->processSlotContent($slotContent);

            // Add slot content to attributes if not empty
            if (!empty(trim($processedSlotContent))) {
                $attributes['slot'] = $processedSlotContent;
            }

            $attributesArray = $this->buildAttributesArray($attributes);

            return "<?= \$this->component('{$componentName}', {$attributesArray}); ?>";
        }, $template);
    }

    /**
     * Process slot content, handling nested components and PHP expressions
     */
    private function processSlotContent(string $content): string
    {
        // Trim the content
        $content = trim($content);

        if (empty($content)) {
            return '';
        }

        // For simple HTML content without PHP or components, escape and quote it
        if (!$this->containsPHPOrComponents($content)) {
            return $this->escapeSlotContent($content);
        }

        // If it contains PHP or components, we need to capture it as a closure
        return "function() { ob_start(); ?>{$content}<?php return ob_get_clean(); }";
    }

    /**
     * Check if content contains PHP expressions or x-components
     */
    private function containsPHPOrComponents(string $content): bool
    {
        // Check for PHP tags
        if (strpos($content, '<?php') !== false || strpos($content, '<?=') !== false) {
            return true;
        }

        // Check for template expressions {{ }} or {!! !!}
        if (preg_match('/\{\{.*?\}\}|\{!!.*?!!\}/', $content)) {
            return true;
        }

        // Check for template directives
        if (preg_match('/@[a-zA-Z]+/', $content)) {
            return true;
        }

        return false;
    }

    /**
     * Escape slot content for safe inclusion in PHP string
     */
    private function escapeSlotContent(string $content): string
    {
        // Remove extra whitespace while preserving structure
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        // Escape single quotes and backslashes for PHP string
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $content);

        return $escaped;
    }

    /**
     * Parse attributes from x-component tag
     */
    private function parseXComponentAttributes(string $attributesString): array
    {
        if (empty(trim($attributesString))) {
            return [];
        }

        $attributes = [];

        // Match attributes in the format: name="value", name='value', name=value, :name="value", :name='value', :name=value
        // Also handle dynamic attributes with colon prefix (e.g. :$variable)
        $pattern = '/(?:^|\s+)(:)?\$?([\w\-]+)(?:=(["\'])((?:[^"\'\\\\]|\\\\.)*)\3)?/';

        preg_match_all($pattern, $attributesString, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $isDynamic = $match[1] === ':';
            $name = $match[2];
            $rawValue = $match[4] ?? null;

            if ($isDynamic) {
                // If the developer wrote :$variable without ="…", 
                // give it the variable name as the value.
                $value = $rawValue !== null ? $rawValue : "\$$name";
            } else {
                $value = $rawValue !== null ? $rawValue : true;
            }

            $attributes[$name] = $value;
        }

        return $attributes;
    }

    /**
     * Build PHP array string from attributes
     */
    private function buildAttributesArray(array $attributes): string
    {
        if (empty($attributes)) {
            return '[]';
        }

        $pairs = [];
        foreach ($attributes as $key => $value) {
            $escapedKey = addslashes($key);

            if ($value === true) {
                $pairs[] = "'{$escapedKey}' => true";
            } elseif ($value === false) {
                $pairs[] = "'{$escapedKey}' => false";
            } elseif ($key === 'slot') {
                // Special handling for slot content
                if (is_string($value) && strpos($value, 'function()') === 0) {
                    // It's a closure for dynamic content
                    $pairs[] = "'{$escapedKey}' => ({$value})()";
                } else {
                    // It's a simple string
                    $pairs[] = "'{$escapedKey}' => '{$value}'";
                }
            } elseif (is_string($value)) {
                // Check if it's a PHP variable or expression
                if ($this->isPHPExpression($value)) {
                    $pairs[] = "'{$escapedKey}' => {$value}";
                } else {
                    $escapedValue = addslashes($value);
                    $pairs[] = "'{$escapedKey}' => '{$escapedValue}'";
                }
            } else {
                $pairs[] = "'{$escapedKey}' => {$value}";
            }
        }

        return '[' . implode(', ', $pairs) . ']';
    }

    /**
     * Check if a value is a PHP expression
     */
    private function isPHPExpression(string $value): bool
    {
        $value = trim($value);

        return preg_match('/^\$[a-zA-Z_][\w]*(?:->[a-zA-Z_][\w]*|\[[^\]]*\])*$/', $value) ||
            preg_match('/^[a-zA-Z_][\w]*\s*\(.*\)$/', $value) ||
            preg_match('/^\[.*\]$/', $value) ||
            is_numeric($value) ||
            in_array(strtolower($value), ['true', 'false', 'null']);
    }

    /**
     * Compile @include directive
     * 
     * @param string $template
     * @return string
     */
    private function compileIncludes(string $template): string
    {
        // Compile @includeWhen directive
        $template = preg_replace_callback(
            '/\@includeWhen\s*\(\s*([^,)]+)\s*,\s*([^,)]+)(?:\s*,\s*(.+))?\s*\)/s',
            function ($matches) {
                $condition = trim($matches[1]);
                $viewExpr = trim($matches[2]);
                $dataExpr = isset($matches[3]) ? trim($matches[3]) : '[]';
                return "<?php if({$condition}): echo \$this->include({$viewExpr}, {$dataExpr}); endif; ?>";
            },
            $template
        );

        // Compile @includeIf directive
        $template = preg_replace_callback(
            '/\@includeIf\s*\(\s*([^,)]+)(?:\s*,\s*(.+))?\s*\)/s',
            function ($matches) {
                $viewExpr = trim($matches[1]);
                $dataExpr = isset($matches[2]) ? trim($matches[2]) : '[]';
                return "<?php if(\$this->templateExists({$viewExpr})): echo \$this->include({$viewExpr}, {$dataExpr}); endif; ?>";
            },
            $template
        );

        // Compile @include directive
        return preg_replace_callback(
            '/\@include\s*\(\s*([^,)]+)(?:\s*,\s*(.+))?\s*\)/s',
            function ($matches) {
                $viewExpr = trim($matches[1]);
                $dataExpr = isset($matches[2]) ? trim($matches[2]) : '[]';
                return "<?= \$this->include($viewExpr, $dataExpr); ?>";
            },
            $template
        );
    }

    /**
     * Compile @use directive
     * 
     * @param string $template
     * @return string
     */
    private function compileUseDirective(string $template): string
    {
        // Pattern to match @use('namespace\Class') or @use('namespace\Class', 'alias')
        return preg_replace_callback(
            '/\@use\s*\(\s*([^,)]+)(?:\s*,\s*([^,)]+))?\s*\)/s',
            function ($matches) {
                $classExpr = trim($matches[1]);
                $aliasExpr = isset($matches[2]) ? trim($matches[2]) : null;

                // Remove quotes from class name for use statement
                $className = trim($classExpr, '\'"');

                if ($aliasExpr) {
                    // Remove quotes from alias
                    $alias = trim($aliasExpr, '\'"');
                    return "<?php use {$className} as {$alias}; ?>";
                } else {
                    return "<?php use {$className}; ?>";
                }
            },
            $template
        );
    }

    /**
     * Compile Blade comments {{-- --}}
     * Remove them completely from the template
     * 
     * @param string $template
     * @return string
     */
    private function compileComments(string $template): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', $template);
    }


    /**
     * Compile echo statements {{ }} and {!! !!}
     * 
     * @param string $template
     * @return string
     */
    private function compileEchos(string $template): string
    {
        // Raw echo {!! !!} - don't escape HTML
        $template = preg_replace('/\{\!\!\s*(.+?)\s*\!\!\}/s', '<?= $1; ?>', $template);

        // Escaped echo {{ }} - escape HTML for security
        $template = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/s', function ($matches) {
            $expression = trim($matches[1]);
            return "<?= e($expression); ?>";
        }, $template);

        return $template;
    }

    /**
     * Compile directives (@if, @foreach, etc.)
     * 
     * @param string $template
     * @return string
     */
    private function compileDirectives(string $template): string
    {
        // Define directive configurations to reduce duplication
        $conditionalDirectives = [
            'if' => ['open' => 'if(%s):', 'close' => 'endif;'],
            'elseif' => ['open' => 'elseif(%s):', 'close' => null],
            'unless' => ['open' => 'if(!(%s)):', 'close' => 'endif;'],
            'isset' => ['open' => 'if(isset(%s)):', 'close' => 'endif;'],
            'empty' => ['open' => 'if(empty(%s)):', 'close' => 'endif;'],
            'can' => ['open' => 'if(can(%s)):', 'close' => 'endif;'],
            'cannot' => ['open' => 'if(cannot(%s)):', 'close' => 'endif;'],
            'auth' => ['open' => 'if(!is_guest()):', 'close' => 'endif;'],
            'guest' => ['open' => 'if(is_guest()):', 'close' => 'endif;'],
            'hasSection' => ['open' => 'if($this->hasSection(%s)):', 'close' => 'endif;'],
            'sectionMissing' => ['open' => 'if(!$this->hasSection(%s)):', 'close' => 'endif;'],
            'session' => ['open' => 'if(session()->has(%s)):', 'close' => 'endif;'],
            'switch' => ['open' => 'switch(%s):case 1.9996931348623157e+308:break;', 'close' => 'endswitch;'],
        ];

        $loopDirectives = [
            'foreach' => ['open' => 'foreach(%s):', 'close' => 'endforeach;'],
            'for' => ['open' => 'for(%s):', 'close' => 'endfor;'],
            'while' => ['open' => 'while(%s):', 'close' => 'endwhile;']
        ];

        $outputDirectives = [
            'dump' => 'dump(%s);',
            'dd' => 'dd(%s);',
            'abort' => 'abort(%s);',
            'old' => 'echo e(old(%s));',
            'share' => '$this->share(%s);',
            'authorize' => 'authorize(%s);',
            'json' => 'echo \Spark\Support\Js::from(%s);',
            'case' => 'case %s:',
            'vite' => 'echo vite(%s);',
            'method' => 'echo method(%s);',
            'checked' => "echo (%s) ? 'checked=\"true\"' : '';",
            'disabled' => "echo (%s) ? 'disabled=\"true\"' : '';",
            'selected' => "echo (%s) ? 'selected=\"true\"' : '';",
            'readonly' => "echo (%s) ? 'readonly=\"true\"' : '';",
            'required' => "echo (%s) ? 'required=\"true\"' : '';",
            'style' => "echo 'style=\"' . \$this->compileStyleArray(%s) . '\"';",
            'class' => "echo 'class=\"' . \$this->compileClassArray(%s) . '\"';",
            'errors' => "if(\$errors->any() && \$errors->has('%s')): foreach(\$errors->get('%s') as \$message):",
            'error' => "if(\$errors->any() && \$errors->has('%s')): \$message = \$errors->first('%s');",
        ];

        $singleLineDirectives = [
            'break' => '<?php break; ?>',
            'continue' => '<?php continue; ?>',
            'default' => '<?php default: ?>',
            'vite' => '<?= vite(); ?>',
            'csrf' => '<?= csrf(); ?>',
            'else' => '<?php else: ?>',
            'endif' => '<?php endif; ?>',
            'enderrors' => '<?php endif; ?>',
            'enderror' => '<?php endif; ?>',
        ];

        // Compile conditional directives
        foreach ($conditionalDirectives as $directive => $config) {
            $template = $this->compileDirectiveWithExpression($template, $directive, $config['open'], $config['close']);
        }

        // Compile loop directives
        foreach ($loopDirectives as $directive => $config) {
            $template = $this->compileDirectiveWithExpression($template, $directive, $config['open'], $config['close']);
        }

        // Compile output directives
        foreach ($outputDirectives as $directive => $phpCode) {
            $template = $this->compileDirectiveWithExpression($template, $directive, $phpCode);
        }

        // Compile custom directives
        $template = $this->compileCustomDirectives($template);

        //  Compile single-line directives
        foreach ($singleLineDirectives as $directive => $phpCode) {
            $template = $this->compileSingleLineDirective($template, $directive, $phpCode);
        }

        return $template;
    }

    /**
     * Compile directive with expression using proper parentheses matching
     */
    private function compileDirectiveWithExpression(string $template, string $directive, string $openTemplate, ?string $closeTemplate = null): string
    {
        // Find all occurrences of @directive(
        $pattern = '/\@' . preg_quote($directive, '/') . '\s*\(/';
        $offset = 0;

        while (preg_match($pattern, $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $matches[0][1];
            $parenStart = $matchStart + strlen($matches[0][0]) - 1; // Position of opening (

            // Find the matching closing parenthesis
            $expression = $this->extractBalancedExpression($template, $parenStart);

            if ($expression === false) {
                throw new InvalidArgumentException("Unbalanced parentheses in @{$directive} directive");
            }

            // Build the replacement
            $replacement = sprintf('<?php %s ?>', sprintf($openTemplate, $expression));

            // Replace the directive with compiled PHP
            $directiveLength = $parenStart - $matchStart + strlen($expression) + 2; // +2 for opening and closing parentheses
            $template = substr_replace($template, $replacement, $matchStart, $directiveLength);

            // Update offset for next search
            $offset = $matchStart + strlen($replacement);
        }

        // Handle closing directive if it exists
        if ($closeTemplate) {
            $closeDirective = "end$directive";
            $template = preg_replace(
                '/\@' . preg_quote($closeDirective, '/') . '\b/',
                sprintf('<?php %s ?>', $closeTemplate),
                $template
            );
        }

        return $template;
    }

    /**
     * Extract expression between balanced parentheses
     */
    private function extractBalancedExpression(string $template, int $startPos): string|false
    {
        $length = strlen($template);

        if ($startPos >= $length || $template[$startPos] !== '(') {
            return false;
        }

        $depth = 0;
        $inString = false;
        $stringChar = null;
        $escaped = false;

        for ($i = $startPos; $i < $length; $i++) {
            $char = $template[$i];

            // Handle string literals
            if (!$escaped && ($char === '"' || $char === "'")) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    $inString = false;
                    $stringChar = null;
                }
            }

            // Handle escape sequences
            if ($char === '\\' && !$escaped) {
                $escaped = true;
                continue;
            }
            $escaped = false;

            // Only count parentheses outside of strings
            if (!$inString) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;

                    if ($depth === 0) {
                        // Found the matching closing parenthesis
                        return substr($template, $startPos + 1, $i - $startPos - 1);
                    }
                }
            }
        }

        // Unbalanced parentheses
        return false;
    }

    /**
     * Compile single-line directives like @break, @continue, etc.
     * 
     * @param string $template
     * @param string $directive
     * @param string $phpCode
     * @return string
     */
    public function compileSingleLineDirective(string $template, string $directive, string $phpCode): string
    {
        $template = preg_replace('/\@' . preg_quote($directive, '/') . '\b/', $phpCode, $template);
        return $template;
    }

    /**
     * Compile custom directives
     */
    private function compileCustomDirectives(string $template): string
    {
        foreach ($this->customDirectives as $directive => $callback) {
            $pattern = '/\@' . preg_quote($directive, '/') . '\s*\(/';
            $offset = 0;

            while (preg_match($pattern, $template, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                $matchStart = $matches[0][1];
                $parenStart = $matchStart + strlen($matches[0][0]) - 1;

                $expression = $this->extractBalancedExpression($template, $parenStart);

                if ($expression === false) {
                    throw new InvalidArgumentException("Unbalanced parentheses in @{$directive} directive");
                }

                $replacement = $callback($expression);
                $directiveLength = $parenStart - $matchStart + strlen($expression) + 2;
                $template = substr_replace($template, $replacement, $matchStart, $directiveLength);

                $offset = $matchStart + strlen($replacement);
            }
        }

        return $template;
    }

    /**
     * Compile @php blocks
     * 
     * @param string $template
     * @return string
     */
    private function compilePhpBlocks(string $template): string
    {
        // Only match single-line @php() directives
        return preg_replace('/\@php\s*\(\s*(.+?)\s*\)(?!\s*@endphp)/', '<?php $1; ?>', $template);
    }

    /**
     * Register a custom directive
     * 
     * @param string $name
     * @param callable $callback
     * @return void
     */
    public function directive(string $name, callable $callback): void
    {
        $this->customDirectives[$name] = $callback;
    }

    /**
     * Get compiled template path
     * 
     * @param string $template
     * @return string
     */
    public function getCompiledPath(string $template): string
    {
        return $this->cachePath . '/' . str_replace(['/', '.'], '_', $template) . '_' . md5($template) . '.php';
    }

    /**
     * Check if template needs recompilation
     * 
     * @param string $templatePath
     * @param string $compiledPath
     * @return bool
     */
    public function isExpired(string $templatePath, string $compiledPath): bool
    {
        if (!file_exists($compiledPath)) {
            return true;
        }

        return filemtime($templatePath) > filemtime($compiledPath);
    }

    /**
     * Ensure cache directory exists
     * 
     * @return void
     */
    private function ensureCacheDirectoryExists(): void
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    /**
     * Clear compiled templates cache
     * 
     * @return void
     */
    public function clearCache(): void
    {
        $files = glob("{$this->cachePath}/*.php");
        foreach ($files as $file) {
            unlink($file);
        }
    }
}
