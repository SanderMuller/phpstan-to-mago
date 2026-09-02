<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use LogicException;

/**
 * The PHP target: a Mago SDK plugin instead of a compiled-in Rust rule.
 *
 * Why a second language at all, when the Rust target already works: the generated Rust only runs
 * compiled inside Mago's analyzer crate, so it cannot ship as a package. A PHP plugin is an ordinary
 * composer library, and the hand-written PHP rules this backend targets measured 62x to 128x cheaper
 * CPU than PHPStan on the same corpus, so almost nothing is given up by staying in PHP.
 *
 * Statement shapes translate cleanly. What does not, yet, is the expression text inside them: the
 * producers still emit Rust (`support::is_name(node.class)`, `b"static"`), so this backend refuses
 * rather than lexically rewriting it. A wrong rule is worse than no rule, and a regex over Rust
 * source is exactly how one gets written.
 */
final class PhpBackend implements Backend
{
    public function bail(): string
    {
        return 'return;';
    }

    public function render(Stm $s): string
    {
        $pad = str_repeat(' ', $s->indent);
        $a = $s->args;

        switch ($s->kind) {
            case 'raw':
                throw new Refusal('raw Rust statement has no PHP rendering: ' . trim($a['text']));
            case 'continue':
                return $pad . "continue;\n";
            case 'bail':
                return $pad . $this->bail() . "\n";
            case 'comment':
                return "{$pad}// {$a['text']}\n";
            case 'guard':
                return "{$pad}if ({$this->checked($a['condition'])}) {\n{$pad}    {$a['exit']}\n{$pad}}\n\n";
            case 'bind-adapter':
                // The PHP helpers navigate, so they need the context that Rust's adapters do not.
                return $this->bind($pad, $a['bind'], $this->checked($this->call($a['adapter'], ['$context', $a['subject']])), $a['exit']);
            case 'bind-arg':
                return $this->bind($pad, $a['bind'], $this->call('positional_arg_at', [$a['args'], $a['index']]), $a['exit']);
            case 'if-open':
                return "{$pad}if ({$this->checked($a['condition'])}) {\n";
            case 'else':
                return "{$pad}} else {\n";
            case 'block-close':
                return "{$pad}}\n\n";
            case 'assign':
            case 'declare':
                return "{$pad}\${$this->name($a['target'])} = {$this->checked($a['value'])};\n";
            case 'foreach-keyed-open':
                return "{$pad}foreach ({$this->checked($a['iterable'])} as \${$this->name($a['key'])} => \${$this->name($a['variable'])}) {\n";
            case 'foreach-open':
                return "{$pad}foreach ({$this->checked($a['iterable'])} as \${$this->name($a['variable'])}) {\n";
            case 'for-open':
                return "{$pad}foreach ({$this->expr($a['subject'])} as \$item) {\n";
            case 'collected-value':
                $name = $s->unused ? '_' . $a['name'] : $a['name'];

                return "{$pad}\${$this->name($name)} = Support::collectedValue(\$item, {$a['index']});\n";
            case 'declare-list':
                return "{$pad}\${$this->name($a['target'])} = [];\n";
            case 'declare-null':
                return "{$pad}\${$this->name($a['target'])} = null;\n";
            case 'break':
                return "{$pad}break;\n";
            case 'append':
                return "{$pad}\${$this->name($a['target'])}[] = {$this->checked($a['value'])};\n";
            case 'check-call':
                return "{$pad}\$this->{$a['name']}({$a['arguments']});\n";
            case 'pass-call':
                // A runtime pass that decides *and* reports, so there is no message here to render. The
                // call arrives already written, because only the transpiler knows what the rule handed it.
                return "{$pad}{$a['call']};\n";
            case 'blank':
                return "\n";
            case 'report':
                // The code arrives already written as PHP — quoted when it is a literal, bare when the rule
                // computes it — because only the transpiler knows which it is.
                return "{$pad}\$context->report(\n"
                    . "{$pad}    Level::Error,\n"
                    . "{$pad}    {$a['code']},\n"
                    // Wrapped rather than emitted conditionally: only the runtime knows whether the node it
                    // is handed sits in a trait, and the helper hands the message back untouched when it
                    // does not. A method declared in a trait is reported once here and once per *using*
                    // class by PHPStan, so the users are named instead of the finding being repeated.
                    . "{$pad}    Issue::new(Support::viaTraitUsers(\$context, \$node, {$a['message']}), {$a['anchor']}, 'here'),\n"
                    . "{$pad});\n\n";
            default:
                throw new LogicException("no PHP rendering for statement kind {$s->kind}");
        }
    }

    /**
     * Markers that a rendered operand is still Rust.
     *
     * Conditions arrive already rendered by the expression producers, so this backend cannot re-derive
     * them; what it can do is refuse to pass one on unchecked. Without this, a rule whose condition
     * never got a PHP rendering was reported as emitted and written out as a `.php` file that either
     * did not parse or, worse, parsed and did the wrong thing.
     */
    private const array RUST_MARKERS = ['support::', 'b"', 'Ok(', '&node.', '.iter()', '.as_slice()', '.is_some_and(', '|item|', 'metadata)', 'node.'];

    private function checked(string $rendered): string
    {
        foreach (self::RUST_MARKERS as $marker) {
            if (str_contains($rendered, $marker)) {
                throw new Refusal("operand is still Rust and has no PHP rendering yet: {$rendered}");
            }
        }

        return $rendered;
    }

    /** `$x = Support::f(..); if ($x === null) { return; }` stands in for Rust's let-else. */
    private function bind(string $pad, string $bind, string $value, string $exit): string
    {
        $name = $this->name($bind);

        return "{$pad}\${$name} = {$value};\n{$pad}if (\${$name} === null) {\n{$pad}    {$exit}\n{$pad}}\n\n";
    }

    /**
     * @param list<string> $args
     *
     * Operands arriving from the statement layer are still Rust and go through {@see expr}; operands
     * built by an expression producer are already rendered and pass through.
     */
    public function call(string $helper, array $args): string
    {
        return 'Support::' . $this->camel($helper) . '(' . implode(', ', $args) . ')';
    }

    public function bytes(string $literal): string
    {
        // Callers differ: most arrive already escaped for a Rust byte string, where a backslash and a
        // double quote are written `\\` and `\"`; some hand over a class name as written. The old
        // `stripcslashes()` served the first and silently broke the second, because it also interprets C
        // escapes — `Doctrine\Bundle\...` lost every separator and the emitted rule named a class that
        // does not exist. Undoing exactly the two sequences Rust escapes serves both, and leaves any other
        // backslash alone.
        $unescaped = '';
        $length = strlen($literal);
        for ($index = 0; $index < $length; ++$index) {
            $character = $literal[$index];
            $next = $literal[$index + 1] ?? '';
            if ($character === '\\' && ($next === '\\' || $next === '"')) {
                $unescaped .= $next;
                ++$index;

                continue;
            }

            $unescaped .= $character;
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $unescaped) . "'";
    }

    /**
     * PHP has a ternary, so the guard chain an inlined helper becomes reads as one.
     *
     * The common shape is a helper that bails to false, which Rust writes as
     * `if !(a) { false } else { b }`; that is `a && b` and worth emitting as such, because the
     * generated conditions are read by people deciding whether to trust the port.
     */
    public function conditional(string $condition, string $then, string $otherwise): string
    {
        $negated = self::withoutTheLeadingNot($condition);
        if ($negated !== null && $then === 'false') {
            return '(' . $negated . ') && (' . $otherwise . ')';
        }

        if ($negated !== null && $then === 'true') {
            return '!(' . $negated . ') || (' . $otherwise . ')';
        }

        return "({$condition} ? {$then} : {$otherwise})";
    }

    /**
     * What a whole condition negates, or null when the condition is not one negation.
     *
     * The paired parenthesis has to be the *last* character, and testing for `!(` and `)` at the two ends
     * does not say that. `!(a) || !(b)` passes both tests and unwrapping it gives `a) || !(b`, which the
     * caller then rebuilt as `(a) || !(b) && (rest)` — De Morgan applied to one operand, the connective left
     * alone, and the result a rule that reports what the original allows. `NoRoutingPrefixRule` is the shape
     * that surfaced it: `! $name instanceof Identifier || $name->toString() !== 'import'` bailing to false.
     *
     * Balanced by depth, like `Translator::stripOuterParentheses()`, and conservative when it cannot tell:
     * answering null keeps the ternary, which is correct for every shape.
     */
    private static function withoutTheLeadingNot(string $condition): ?string
    {
        if (! str_starts_with($condition, '!(') || ! str_ends_with($condition, ')')) {
            return null;
        }

        $depth = 0;
        $length = strlen($condition);
        for ($index = 1; $index < $length; ++$index) {
            $depth += ($condition[$index] === '(' ? 1 : ($condition[$index] === ')' ? -1 : 0));
            if ($depth === 0) {
                return $index === $length - 1 ? substr($condition, 2, -1) : null;
            }
        }

        return null;
    }

    private function name(string $rust): string
    {
        return ltrim($rust, '&');
    }

    private function camel(string $snake): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $snake))));
    }

    /**
     * Expression operands still arrive as Rust source, which this backend will not guess at.
     *
     * The two forms it does accept are the ones the statement layer produces itself rather than
     * receiving from an expression producer: the hook's node, and a borrow of it.
     */
    private function expr(string $rust): string
    {
        $trimmed = ltrim($rust, '&');
        if ($trimmed === 'node') {
            return '$node';
        }

        if ($trimmed === 'context') {
            return '$context';
        }

        if (preg_match('/^node\.([a-z_]+)$/', $trimmed, $match) === 1) {
            return '$node->' . $this->camel($match[1]);
        }

        throw new Refusal("expression is still Rust and has no PHP rendering yet: {$rust}");
    }
}
