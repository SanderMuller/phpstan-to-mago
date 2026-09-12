<?php

declare(strict_types=1);

namespace Sandermuller\PhpstanToMago;

use RuntimeException;

/**
 * The files that register generated rules with Mago, for the Rust targets.
 *
 * Only the Rust targets need these: a PHP plugin registers itself through the SDK, so the PHP target
 * writes rule files and nothing else.
 */
final class ModuleEmitter
{
    /**
     * @param list<array{name: string, trait: string, node: string|null, kind: string, module: string, rust: string, identifier: string|null, identifiers: list<string>, arguments: array<string, mixed>, messages: list<string>}> $rules
     */
    public static function module(array $rules): string
    {
        // By name, not by the order the files were passed in: a generator whose output depends on argv
        // produces a diff full of moved blocks the next time someone regenerates it from a shell glob.
        usort($rules, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        $registrations = [];
        $imports = [];
        foreach ($rules as $rule) {
            $register = match ($rule['trait']) {
                'MethodCallHook' => 'register_method_call_hook',
                'FunctionCallHook' => 'register_function_call_hook',
                'StaticMethodCallHook' => 'register_static_method_call_hook',
                'ExpressionHook' => 'register_expression_hook',
                'StatementHook' => 'register_statement_hook',
                'ClassDeclarationHook' => 'register_class_hook',
                'InterfaceDeclarationHook' => 'register_interface_hook',
                'TraitDeclarationHook' => 'register_trait_hook',
                'ClassLikeMemberHook' => 'register_class_like_member_hook',
                'AnalysisHook' => 'register_analysis_hook',
                default => throw new RuntimeException("no registration for {$rule['trait']}"),
            };
            $registrations[] = "        registry.{$register}({$rule['name']});";
            $imports[$rule['trait']] = "use crate::plugin::{$rule['trait']};";
        }

        ksort($imports);

        $used = [];
        foreach ($rules as $rule) {
            if ($rule['node'] !== null) {
                $used[$rule['node']] = true;
            }
        }

        $nodeImports = [];
        foreach (array_keys($used) as $node) {
            $nodeImports[] = "use mago_syntax::cst::{$node};";
        }

        // Every class-like declaration hook and the class-like-member hook take the enclosing metadata: the
        // interface and trait hooks are handed a `&ClassLikeMetadata` too, not a kind of their own.
        $traits = array_column($rules, 'trait');
        if (array_intersect(['ClassDeclarationHook', 'InterfaceDeclarationHook', 'TraitDeclarationHook', 'ClassLikeMemberHook'], $traits) !== []) {
            $nodeImports[] = 'use mago_codex::metadata::class_like::ClassLikeMetadata;';
        }

        $nodeImports = array_values(array_unique($nodeImports));
        sort($nodeImports);

        $header = implode("\n", [
            '//! Rules emitted by transpile.php. Generated; do not edit.',
            '',
            'pub mod support;',
            '',
            'use mago_reporting::Annotation;',
            'use mago_reporting::Issue;',
            'use mago_span::HasSpan;',
            implode("\n", $nodeImports),
            '',
            'use crate::code::IssueCode;',
            implode("\n", array_values($imports)),
            'use crate::plugin::ExpressionHookResult;',
            'use crate::plugin::HookResult;',
            'use crate::plugin::AnalysisHookContext;',
            'use crate::plugin::Plugin;',
            'use crate::plugin::PluginMeta;',
            'use crate::plugin::PluginRegistry;',
            'use crate::plugin::context::HookContext;',
            'use crate::plugin::provider::Provider;',
            'use crate::plugin::provider::ProviderMeta;',
            '',
            'pub struct GeneratedRulesPlugin;',
            '',
            'static META: PluginMeta =',
            '    PluginMeta::new("generated-rules", "Generated Rules", "PHPStan rules emitted by the transpiler", &[], true);',
            '',
            'impl Plugin for GeneratedRulesPlugin {',
            "    fn meta(&self) -> &'static PluginMeta {",
            '        &META',
            '    }',
            '',
            '    fn register(&self, registry: &mut PluginRegistry) {',
            implode("\n", $registrations),
            '    }',
            '}',
            '',
        ]);

        return $header . "\n" . implode("\n\n", array_column($rules, 'rust')) . "\n";
    }

    /**
     * Assembles the linter-tier rules.
     *
     * Returns the module itself plus the three registration entries per rule, which live in files this
     * generator does not own. They are written out rather than applied so that patching the Mago tree
     * stays a separate, visible step.
     *
     * @param list<array{name: string, trait: string, node: string|null, kind: string, module: string, rust: string, identifier: string|null, identifiers: list<string>, arguments: array<string, mixed>, messages: list<string>}> $rules
     *
     * @return array<string, string>
     */
    public static function lintModule(array $rules): array
    {
        usort($rules, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        $mods = [];
        $uses = [];
        $kinds = [];
        $variants = [];
        $settings = [];
        $configUses = [];
        foreach ($rules as $rule) {
            $mods[] = "pub mod {$rule['module']};";
            $uses[] = "pub use {$rule['module']}::*;";
            $kinds[$rule['kind']] = true;
            $variants[] = "    {$rule['name']}({$rule['module']} @ {$rule['name']}),";
            $settings[] = "    pub {$rule['module']}: RuleSettings<{$rule['name']}Config>,";
            $configUses[] = "use crate::rule::{$rule['name']}Config;";
        }

        sort($mods);
        sort($uses);

        $module = implode("\n", [
            '//! PHPStan rules emitted by transpile.php for the linter tier. Generated; do not edit.',
            '//!',
            '//! These are the rules whose bodies ask no type, hierarchy or block-context question, so they',
            '//! can run where the analysis is only a parse and a name resolution. Everything else the',
            '//! transpiler produces stays on the analyzer tier, and is refused here with a reason.',
            '',
            'pub mod support;',
            '',
            implode("\n", $mods),
            '',
            implode("\n", $uses),
            '',
        ]);

        return [
            'module' => $module,
            'variants' => implode("\n", $variants),
            'settings' => implode("\n", $settings),
            'configUses' => implode("\n", $configUses),
        ];
    }
}
