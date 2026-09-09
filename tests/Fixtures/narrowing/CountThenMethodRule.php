<?php declare(strict_types = 1);
namespace Sandermuller\PhpstanToMago\Tests\Fixtures\Narrowing;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
/** @implements Rule<Node\Expr\MethodCall> */
class CountThenMethodRule implements Rule
{
	public function getNodeType(): string { return Node\Expr\MethodCall::class; }
	public function processNode(Node $node, Scope $scope): array
	{
		if (count($node->getArgs()) < 1) { return []; }
		$right = $node->getArgs()[0]->value;
		if (self::isCountFunction($right)) {
			return [RuleErrorBuilder::message('a count function call.')->identifier('probe.fn')->build()];
		}
		if (self::isCountMethod($right)) {
			return [RuleErrorBuilder::message('a count method call.')->identifier('probe.method')->build()];
		}
		return [];
	}
	private static function isCountFunction(Node\Expr $expr): bool
	{
		return $expr instanceof Node\Expr\FuncCall
			&& $expr->name instanceof Node\Name
			&& $expr->name->toLowerString() === 'count';
	}
	private static function isCountMethod(Node\Expr $expr): bool
	{
		return $expr instanceof Node\Expr\MethodCall
			&& $expr->name instanceof Node\Identifier
			&& $expr->name->toLowerString() === 'count';
	}
}
