<?php

namespace Mlangeni\Machinjiri\Core\Views\Services;

use Mlangeni\Machinjiri\Core\Views\Contracts\ViewCompilerInterface;

class ViewCompiler implements ViewCompilerInterface
{
    /**
     * -style comment pattern — stripped before compilation.
     */
    protected string $commentPattern = '/\{\{--.*?--\}\}/s';

    /**
     * Placeholder token for a literal `@` ('s `@@` escape).
     * Uses NUL bytes so it can never collide with real template content.
     */
    protected string $literalAtToken = "\x00__LITERAL_AT__\x00";

    /**
     * Patterns applied in order. Order is critical:
     *
     *  1. Comments stripped first (see compile()).
     *  2. `{!! !!}` before `{{ }}` so `{!!` isn't swallowed.
     *  3. Two-arg variants before one-arg variants.
     *  4. `@empty($x)` before bare `@empty`.
     *  5.  `@directive` and legacy `<% directive %>` coexist freely.
     */
    protected array $patterns = [

        // =================================================================
        // ECHOES
        // =================================================================
        '/\{!!\s*(.+?)\s*!!\}/s'
            => '<?php echo $1; ?>',

        '/\{\{\s*(.+?)\s*\}\}/s'
            => '<?php echo htmlspecialchars((string)($1), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); ?>',

        // =================================================================
        // : LAYOUT / SECTIONS
        // =================================================================
        '/@extends\s*\(\s*([^)]+?)\s*\)/'
            => '<?php View::extend($1); ?>',

        '/@section\s*\(\s*([^,)]+?)\s*,\s*(.+?)\s*\)/'
            => '<?php View::section($1, $2); ?>',

        '/@section\s*\(\s*([^,)]+?)\s*\)/'
            => '<?php View::section($1); ?>',

        '/@endsection/'
            => '<?php View::endSection(); ?>',

        '/@stop/'
            => '<?php View::endSection(); ?>',

        '/@append/'
            => '<?php View::endSection(); ?>',

        '/@overwrite/'
            => '<?php View::endSection(); ?>',

        '/@show/'
            => '<?php View::show(); ?>',

        '/@yield\s*\(\s*([^,)]+?)\s*,\s*(.+?)\s*\)/'
            => '<?php View::yield($1); ?>',

        '/@yield\s*\(\s*([^,)]+?)\s*\)/'
            => '<?php View::yield($1); ?>',

        '/@parent/'
            => '<?php View::parent(); ?>',

        '/@hasSection\s*\(\s*(.+?)\s*\)/'
            => '<?php if (View::hasSection($1)): ?>',

        '/@sectionMissing\s*\(\s*(.+?)\s*\)/'
            => '<?php if (!View::hasSection($1)): ?>',

        // =================================================================
        // : INCLUDES
        // =================================================================
        '/@includeIf\s*\(\s*([^,)]+?)\s*,\s*(.+?)\s*\)/'
            => '<?php if (View::viewExists($1)) View::include($1, $2); ?>',

        '/@includeIf\s*\(\s*([^,)]+?)\s*\)/'
            => '<?php if (View::viewExists($1)) View::include($1); ?>',

        '/@includeWhen\s*\(\s*(.+?)\s*,\s*([^,)]+?)\s*,\s*(.+?)\s*\)/'
            => '<?php if ($1) View::include($2, $3); ?>',

        '/@includeWhen\s*\(\s*(.+?)\s*,\s*([^,)]+?)\s*\)/'
            => '<?php if ($1) View::include($2); ?>',

        '/@includeUnless\s*\(\s*(.+?)\s*,\s*([^,)]+?)\s*,\s*(.+?)\s*\)/'
            => '<?php if (!$1) View::include($2, $3); ?>',

        '/@includeUnless\s*\(\s*(.+?)\s*,\s*([^,)]+?)\s*\)/'
            => '<?php if (!$1) View::include($2); ?>',

        '/@include\s*\(\s*([^,)]+?)\s*,\s*(.+?)\s*\)/'
            => '<?php View::include($1, $2); ?>',

        '/@include\s*\(\s*([^,)]+?)\s*\)/'
            => '<?php View::include($1); ?>',

        // =================================================================
        // : STACKS
        // =================================================================
        '/@push\s*\(\s*(.+?)\s*\)/'
            => '<?php View::push($1); ?>',

        '/@endpush/'
            => '<?php View::endPush(); ?>',

        '/@prepend\s*\(\s*(.+?)\s*\)/'
            => '<?php View::prepend($1); ?>',

        '/@endprepend/'
            => '<?php View::endPrepend(); ?>',

        '/@stack\s*\(\s*(.+?)\s*\)/'
            => '<?php View::stack($1); ?>',

        // =================================================================
        // : CONDITIONALS
        // =================================================================
        '/@if\s*\(\s*(.+?)\s*\)/'
            => '<?php if ($1): ?>',

        '/@elseif\s*\(\s*(.+?)\s*\)/'
            => '<?php elseif ($1): ?>',

        '/@else/'
            => '<?php else: ?>',

        '/@endif/'
            => '<?php endif; ?>',

        '/@unless\s*\(\s*(.+?)\s*\)/'
            => '<?php if (!$1): ?>',

        '/@endunless/'
            => '<?php endif; ?>',

        '/@isset\s*\(\s*(.+?)\s*\)/'
            => '<?php if (isset($1)): ?>',

        '/@endisset/'
            => '<?php endif; ?>',

        // @empty($x) — standalone; must precede bare @empty
        '/@empty\s*\(\s*(.+?)\s*\)/'
            => '<?php if (empty($1)): ?>',

        '/@endempty/'
            => '<?php endif; ?>',

        '/@auth/'
            => '<?php if (function_exists("auth") && auth()->check()): ?>',

        '/@endauth/'
            => '<?php endif; ?>',

        '/@guest/'
            => '<?php if (!function_exists("auth") || !auth()->check()): ?>',

        '/@endguest/'
            => '<?php endif; ?>',

        '/@env\s*\(\s*(.+?)\s*\)/'
            => '<?php if (function_exists("env") && env("APP_ENV") === $1): ?>',

        '/@endenv/'
            => '<?php endif; ?>',

        '/@production/'
            => '<?php if (function_exists("env") && env("APP_ENV") === "production"): ?>',

        '/@endproduction/'
            => '<?php endif; ?>',

        // =================================================================
        // : SWITCH
        // =================================================================
        '/@switch\s*\(\s*(.+?)\s*\)/'
            => '<?php switch ($1): ?>',

        '/@case\s*\(\s*(.+?)\s*\)/'
            => '<?php case $1: ?>',

        '/@default/'
            => '<?php default: ?>',

        '/@endswitch/'
            => '<?php endswitch; ?>',

        // =================================================================
        // : LOOPS
        // =================================================================
        '/@forelse\s*\(\s*(.+?)\s*\)/'
            => '<?php $__empty = true; foreach ($1): $__empty = false; ?>',

        // Bare @empty (inside @forelse) — negative lookahead excludes @empty($x)
        '/@empty\b(?!\s*\()/'
            => '<?php endforeach; if ($__empty): ?>',

        '/@endforelse/'
            => '<?php endif; ?>',

        '/@foreach\s*\(\s*(.+?)\s*\)/'
            => '<?php foreach ($1): ?>',

        '/@endforeach/'
            => '<?php endforeach; ?>',

        '/@for\s*\(\s*(.+?)\s*\)/'
            => '<?php for ($1): ?>',

        '/@endfor/'
            => '<?php endfor; ?>',

        '/@while\s*\(\s*(.+?)\s*\)/'
            => '<?php while ($1): ?>',

        '/@endwhile/'
            => '<?php endwhile; ?>',

        '/@continue\s*\(\s*(.+?)\s*\)/'
            => '<?php if ($1) continue; ?>',

        '/@continue/'
            => '<?php continue; ?>',

        '/@break/'
            => '<?php break; ?>',

        // =================================================================
        // RAW PHP + HELPERS
        // =================================================================
        '/@php\b/'
            => '<?php ',

        '/@endphp/'
            => ' ?>',

        '/@csrf/'
            => '<?php echo function_exists("csrf_field") ? csrf_field() : ""; ?>',

        '/@method\s*\(\s*(.+?)\s*\)/'
            => '<?php echo function_exists("method_field") ? method_field($1) : ""; ?>',

        '/@dd\s*\(\s*(.+?)\s*\)/'
            => '<?php (function_exists("dd") ? dd($1) : die(var_export($1, true))); ?>',

        '/@dump\s*\(\s*(.+?)\s*\)/'
            => '<?php (function_exists("dump") ? dump($1) : var_dump($1)); ?>',

        // =================================================================
        // LEGACY MACHINJIRI `<% %>` DIRECTIVES
        // =================================================================
        '/<%\s*extend\s*\(\s*([^)]+?)\s*\)\s*%>/'
            => '<?php View::extend($1); ?>',

        '/<%\s*extend\s+([^%]+?)\s*%>/'
            => '<?php View::extend($1); ?>',

        '/<%\s*section\s*\(\s*([^,)]+?)\s*,\s*(.+?)\s*\)\s*%>/'
            => '<?php View::section($1, $2); ?>',

        '/<%\s*section\s*\(\s*([^,)]+?)\s*\)\s*%>/'
            => '<?php View::section($1); ?>',

        '/<%\s*endsection\s*%>/'
            => '<?php View::endSection(); ?>',

        '/<%\s*parent\s*%>/'
            => '<?php View::parent(); ?>',

        '/<%\s*yield\s*\(\s*(.+?)\s*\)\s*%>/'
            => '<?php View::yield($1); ?>',

        '/<%\s*content\s*%>/'
            => '<?php View::yield(\'content\'); ?>',

        '/<%\s*hasSection\s*\(\s*(.+?)\s*\)\s*%>/'
            => '<?php if (View::hasSection($1)): ?>',

        '/<%\s*include\s*\(\s*([^,)]+?)\s*,\s*(.+?)\s*\)\s*%>/'
            => '<?php View::include($1, $2); ?>',

        '/<%\s*include\s*\(\s*([^,)]+?)\s*\)\s*%>/'
            => '<?php View::include($1); ?>',

        '/<%\s*include\s+([^,]+?)(?:,\s*(.+?))?\s*%>/'
            => '<?php View::include($1, $2 ?? []); ?>',

        '/<%\s*if\s+(.+?)\s*%>/'
            => '<?php if ($1): ?>',

        '/<%\s*elseif\s+(.+?)\s*%>/'
            => '<?php elseif ($1): ?>',

        '/<%\s*else\s*%>/'
            => '<?php else: ?>',

        '/<%\s*endif\s*%>/'
            => '<?php endif; ?>',

        '/<%\s*foreach\s+(.+?)\s*%>/'
            => '<?php foreach ($1): ?>',

        '/<%\s*endforeach\s*%>/'
            => '<?php endforeach; ?>',

        '/<%\s*forelse\s+(.+?)\s*%>/'
            => '<?php $__empty = true; foreach ($1): $__empty = false; ?>',

        '/<%\s*empty\s*%>/'
            => '<?php endforeach; if ($__empty): ?>',

        '/<%\s*endforelse\s*%>/'
            => '<?php endif; ?>',

        '/<%\s*for\s+(.+?)\s*%>/'
            => '<?php for ($1): ?>',

        '/<%\s*endfor\s*%>/'
            => '<?php endfor; ?>',

        '/<%\s*while\s+(.+?)\s*%>/'
            => '<?php while ($1): ?>',

        '/<%\s*endwhile\s*%>/'
            => '<?php endwhile; ?>',

        '/<%\s*push\s*\(\s*(.+?)\s*\)\s*%>/'
            => '<?php View::push($1); ?>',

        '/<%\s*endpush\s*%>/'
            => '<?php View::endPush(); ?>',

        '/<%\s*prepend\s*\(\s*(.+?)\s*\)\s*%>/'
            => '<?php View::prepend($1); ?>',

        '/<%\s*endprepend\s*%>/'
            => '<?php View::endPrepend(); ?>',

        '/<%\s*stack\s*\(\s*(.+?)\s*\)\s*%>/'
            => '<?php View::stack($1); ?>',

        '/<%\s*php\s*%>/'
            => '<?php ',

        '/<%\s*endphp\s*%>/'
            => ' ?>',
    ];

    public function compile(string $content, ?string $path = null): string
    {
        // 1. Strip  comments before anything else.
        $content = preg_replace($this->commentPattern, '', $content) ?? $content;

        // 2. Protect `@@` → literal `@` escape.
        $content = str_replace('@@', $this->literalAtToken, $content);

        // 3. Apply every pattern in order.
        $compiled = preg_replace(
            array_keys($this->patterns),
            array_values($this->patterns),
            $content
        );

        if ($compiled === null) {
            $suffix = $path ? " in {$path}" : '';
            throw new \RuntimeException(
                "View compilation failed{$suffix}: " . preg_last_error_msg()
            );
        }

        // 4. Restore literal `@`.
        return str_replace($this->literalAtToken, '@', $compiled);
    }
}