## What this changes

<!-- One or two sentences. For a redesign issue, name it: "Redesign 07 - ..." -->

Closes #

## Base branch

<!-- Redesign PRs target redesign/mail-shield, never main: a push to main deploys
     to production. See documentaion/REDESIGN_BRIEF.md section 15. -->

- [ ] This PR targets the correct base branch

## Verification

<!-- What you actually ran and saw - not what you intended to run. -->

- [ ] `php -l` passes on every PHP file touched
- [ ] Semgrep clean (`semgrep scan --config p/php --config p/security-audit --error`)
- [ ] Checked at 375px, 768px, 1024px, 1440px
- [ ] Existing functionality still works (say below which flows you exercised)

## Scope check

- [ ] No backend change: no action/`case` block, SQL, session logic, data model, cron or parser
- [ ] No element `id`, `name`, `data-*` attribute or endpoint that existing JS depends on was renamed
- [ ] No feature claimed that the code does not have (brief section 9)
- [ ] No new CDN dependency, npm, or build step

## Notes for the reviewer

<!-- Anything descoped, anything you found and did not fix, anything you are unsure about. -->
