<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use LogicException;

/**
 * The original target. Every method here reproduces, byte for byte, what the emitter used to append
 * inline, which is what makes the extraction checkable: regenerating the rule set must produce
 * identical files.
 */
final class RustBackend implements Backend
{
    public function bail(): string
    {
        return 'return Ok({BAIL});';
    }

    public function call(string $helper, array $args): string
    {
        return 'support::' . $helper . '(' . implode(', ', $args) . ')';
    }

    public function bytes(string $literal): string
    {
        return 'b"' . $literal . '"';
    }

    public function conditional(string $condition, string $then, string $otherwise): string
    {
        return "if {$condition} { {$then} } else { {$otherwise} }";
    }

    public function render(Stm $s): string
    {
        $pad = str_repeat(' ', $s->indent);
        $a = $s->args;

        switch ($s->kind) {
            case 'raw':
                return $a['text'];
            case 'continue':
                return $pad . "continue;\n";
            case 'bail':
                return $pad . $this->bail() . "\n";
            case 'comment':
                return "{$pad}// {$a['text']}\n";
            case 'guard':
                return "{$pad}if {$a['condition']} {\n{$pad}    {$a['exit']}\n{$pad}}\n\n";
            case 'bind-adapter':
                return "{$pad}let Some({$a['bind']}) = support::{$a['adapter']}({$a['subject']}) else {\n{$pad}    return Ok({BAIL});\n{$pad}};\n\n";
            case 'bind-arg':
                return "{$pad}let Some({$a['bind']}) = support::positional_arg_at({$a['args']}, {$a['index']}) else {\n{$pad}    return Ok({BAIL});\n{$pad}};\n\n";
            case 'if-open':
                return "{$pad}if {$a['condition']} {\n";
            case 'else':
                return "{$pad}} else {\n";
            case 'block-close':
                return "{$pad}}\n\n";
            case 'assign':
                return "{$pad}{$a['target']} = {$a['value']};\n";
            case 'declare':
                return "{$pad}let mut {$a['target']} = {$a['value']};\n";
            case 'foreach-open':
                return "{$pad}for {$a['variable']} in {$a['iterable']} {\n";
            case 'for-open':
                return "{$pad}for item in {$a['subject']} {\n";
            case 'collected-value':
                $name = $s->unused ? '_' . $a['name'] : $a['name'];

                return "{$pad}let {$name} = support::collected_value(&item, {$a['index']});\n";
            case 'declare-list':
            case 'append':
                throw new Refusal('a list a rule builds has no Rust rendering yet');
            case 'blank':
                return "\n";
            default:
                throw new LogicException("no Rust rendering for statement kind {$s->kind}");
        }
    }
}
