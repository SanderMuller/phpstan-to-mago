# Dependencies with known conflicts

## `rector/type-perfect` is deliberately absent

The repo-init baseline ships both `rector/type-perfect` and `tomasvotruba/type-coverage`. Since type-coverage
2.3 absorbed type-perfect's rules under the same namespace, both register
`Rector\TypePerfect\Reflection\MethodNodeAnalyser`, and PHPStan aborts before analysing with "Multiple
services of type ... found". `hihaho/phpstan-rules` v3.15.1 fixed this the same way. Do not re-add it.

## The rule packages are installed to be read, not run

All four — `symplify/phpstan-rules`, `hihaho/phpstan-rules`, `tomasvotruba/type-coverage`,
`tomasvotruba/cognitive-complexity` — are dev dependencies so that CI resolves the same corpus a contributor
does, which is what makes the census meaningful. `hihaho/phpstan-rules` is listed under
`extra."phpstan/extension-installer".ignore`, because registering a corpus's rules against this repository's
own source is not what a corpus is for. Add a new corpus package the same way.
