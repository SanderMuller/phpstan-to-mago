# `is_callable()` keeps a provably non-callable object arm

**Live divergence, mago's side.** Stands for `symfony/console` `Helper/TreeNode.php:76`, found by the corpus
sweep's first run.

PHPStan does three different things to an object atomic under `is_callable()`, depending on what it can prove:

| the object arm | PHPStan | mago |
|---|---|---|
| `final`, no `__invoke` | **removed** — provably not callable | untouched |
| non-`final`, no `__invoke` | **refined** to `callable & Class` | untouched |
| `final`, with `__invoke` | **retained** — it is callable | untouched |

This case pins the first row, which is the only one where the retained arm is provably impossible. Whether to
refine an unprovable atom is a defensible choice and nothing here asks for it.

A consumer asking "is this definitely callable" must require *every* atomic to be callable — answering yes
when any one is would exempt an unnarrowed `string|Closure`, the case the rule exists to report. So an
untouched object atom makes the answer false, and the port reports code the guard has already made safe.

`plain()` is the control both engines must report.
