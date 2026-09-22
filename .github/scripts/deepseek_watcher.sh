#!/usr/bin/env bash
# Plockar det lägst numrerade öppna issuet märkt "Build" och kör det genom
# DeepSeek (claude-subagent), ett i taget i stigande ordning - ursprungligen
# för att redesignens 28 issues byggde sekventiellt på varandra (tokens ->
# partials -> sidor), men samma ordning håller kvar för senare Build-märkta
# arbeten som själva är numrerade i steg (t.ex. #160-162, "1/3".."3/3").
# redesign/mail-shield är sedan #158 mergad till main och borttagen (main och
# prod matchar), så BASE_BRANCH pekar nu mot main - att fortsätta peka mot en
# borttagen gren fick git-fetchen nedan att misslyckas under set -euo
# pipefail, innan skriptet ens hann lista issues, vilket är varför inget
# Build-märkt issue plockades upp. Öppnar en PR mot BASE_BRANCH.
#
# En build/*-PR mergas automatiskt av en senare körning när den är grön och
# inte väntar på användaren (se auto_merge_green_build_prs nedan). Allt annat
# lämnas åt en människa. Obs: en merge till main deployar till produktion via
# prod.yml, som kör om Semgrep innan deployen.
#
# Väntar med att plocka nästa issue tills en eventuell öppen build/*-PR är
# mergad eller stängd, för att undvika att senare steg bygger på grenar som
# ännu inte landat på BASE_BRANCH.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKTREE_BASE="$REPO_ROOT/.claude/worktrees"
LOCK_PATH="$REPO_ROOT/.claude/deepseek-watcher.lock"
LOG_DIR="$REPO_ROOT/.claude/logs"
GH_REPO="Manjo-Consulting-AB/tempmail"
BASE_BRANCH="main"
# Etikett som betyder "väntar på input från användaren" - en PR med den
# mergas aldrig automatiskt. Sätts av subagenten (se task.md) eller för hand.
NEEDS_INPUT_LABEL="needs-input"

mkdir -p "$WORKTREE_BASE" "$LOG_DIR" "$(dirname "$LOCK_PATH")"

exec 9>"$LOCK_PATH"
flock -n 9 || { echo "Redan igång, hoppar över."; exit 0; }

# DeepSeeks peak hours (UTC, vardagar): 01:00-03:59 och 06:00-09:59.
# Samma fönster som mimers process_next_issue.py undviker.
now_hour=$(date -u +%H)
now_dow=$(date -u +%u)  # 1=mån .. 7=sön
if [ "$now_dow" -le 5 ]; then
  if { [ "$now_hour" -ge 1 ] && [ "$now_hour" -le 3 ]; } || { [ "$now_hour" -ge 6 ] && [ "$now_hour" -le 9 ]; }; then
    echo "Hoppar över: DeepSeek peak hours (UTC)."
    exit 0
  fi
fi

cd "$REPO_ROOT"

# Mergar varje öppen build/*-PR som är grön och inte kräver mer input:
# - inte draft, och utan etiketten $NEEDS_INPUT_LABEL;
# - ingen granskning som begär ändringar (reviewDecision CHANGES_REQUESTED);
# - minst en check, och varje check avslutad som SUCCESS/NEUTRAL/SKIPPED
#   (en check som fortfarande kör räknas inte som grön - nästa körning tar den);
# - mergebar mot BASE_BRANCH (ingen konflikt, inga ouppfyllda skyddsregler).
# Grenen raderas inte: ls-remote-kollen nedan använder den för att inte plocka
# upp samma issue igen.
auto_merge_green_build_prs() {
  local prs n
  prs=$(gh pr list --repo "$GH_REPO" --state open --base "$BASE_BRANCH" --json number,headRefName \
    -q '.[] | select(.headRefName | startswith("build/")) | .number')
  for n in $prs; do
    local ready
    ready=$(gh pr view "$n" --repo "$GH_REPO" \
      --json isDraft,labels,reviewDecision,mergeable,mergeStateStatus,statusCheckRollup \
      -q '
        (.statusCheckRollup // []) as $c
        | (.isDraft | not)
          and ([.labels[].name] | index("'"$NEEDS_INPUT_LABEL"'") | not)
          and (.reviewDecision != "CHANGES_REQUESTED")
          and (.mergeable == "MERGEABLE")
          and (.mergeStateStatus == "CLEAN" or .mergeStateStatus == "HAS_HOOKS")
          and ($c | length) > 0
          and all($c[]; (.conclusion // .state) as $s
                        | $s == "SUCCESS" or $s == "NEUTRAL" or $s == "SKIPPED")' 2>/dev/null || echo false)
    if [ "$ready" != "true" ]; then
      echo "PR #$n är inte redo för automerge (ej grön, väntar på input eller ej mergebar)."
      continue
    fi
    local pr_url head
    pr_url=$(gh pr view "$n" --repo "$GH_REPO" --json url -q .url)
    head=$(gh pr view "$n" --repo "$GH_REPO" --json headRefName -q .headRefName)
    if gh pr merge "$n" --repo "$GH_REPO" --merge; then
      echo "Automergade PR #$n ($head)."
      git worktree remove --force "$WORKTREE_BASE/$head" 2>/dev/null || true
      ~/.local/bin/notify-tony "PR #$n automergad (grön, ingen input krävdes): $pr_url" "Tempmail build" 2>/dev/null || true
    else
      ~/.local/bin/notify-tony "PR #$n var grön men automerge misslyckades: $pr_url" "Tempmail build - fel" 2>/dev/null || true
    fi
  done
}
auto_merge_green_build_prs

git fetch origin "$BASE_BRANCH" --quiet

open_build_pr=$(gh pr list --repo "$GH_REPO" --state open --json headRefName -q '[.[] | select(.headRefName | startswith("build/"))] | length')
if [ "$open_build_pr" -gt 0 ]; then
  echo "En build-PR väntar redan på granskning, kör inget nytt."
  exit 0
fi

issue_num=""
for n in $(gh issue list --repo "$GH_REPO" --state open --label Build --json number -q '.[].number' | sort -n); do
  if git ls-remote --heads origin "build/$n" | grep -q .; then
    continue  # redan startat tidigare (PR mergad/stängd, eller pågående push)
  fi
  issue_num="$n"
  break
done

if [ -z "$issue_num" ]; then
  echo "Inget Build-märkt issue att plocka upp."
  exit 0
fi

branch="build/$issue_num"
worktree="$WORKTREE_BASE/$branch"
log="$LOG_DIR/issue-$issue_num.log"

# Städa upp en eventuell rest efter ett tidigare misslyckat försök på samma
# issue (grenen pushades då aldrig, så ls-remote-kollen ovan missade den).
if [ -d "$worktree" ]; then
  git worktree remove --force "$worktree" 2>/dev/null || true
fi
git branch -D "$branch" 2>/dev/null || true

echo "Startar issue #$issue_num på $branch"
git worktree add "$worktree" -b "$branch" "origin/$BASE_BRANCH"

issue_title=$(gh issue view "$issue_num" --repo "$GH_REPO" --json title -q .title)
issue_body=$(gh issue view "$issue_num" --repo "$GH_REPO" --json body -q .body)

task_file="$worktree/task.md"
cat > "$task_file" <<EOF
Du arbetar i grenen \`$branch\` i repot tempmail (Manjo Consulting), grenad
från \`$BASE_BRANCH\`. Det här är issue #$issue_num, ett steg i ett arbete som
är numrerat och byggs sekventiellt (se issue-titeln och tidigare Build-issues
för sammanhanget).

VIKTIGT: en push till main deployar direkt till produktion (se prod.yml) -
pusha bara till \`$branch\`, aldrig direkt till main.

Bygg exakt det scope-avsnittet nedan beskriver, inget mer. När du är klar:
1. Committa dina ändringar med ett beskrivande meddelande.
2. Pusha grenen: git push -u origin $branch
3. Öppna en PR mot \`$BASE_BRANCH\` med "gh pr create --base $BASE_BRANCH", med "Closes #$issue_num" i PR-kroppen.
4. Mergea INTE PR:en själv. En grön PR mergas automatiskt av watchern.
5. Om du behöver ett beslut eller svar från användaren innan PR:en kan
   mergas (oklart scope, en avvägning du inte kan avgöra, något du inte
   kunde verifiera): skriv frågan i PR-kroppen och sätt etiketten
   "$NEEDS_INPUT_LABEL" med
   "gh pr edit --add-label $NEEDS_INPUT_LABEL" (skapa den först med
   "gh label create $NEEDS_INPUT_LABEL --force" om den saknas). Då mergas den
   inte automatiskt.

## $issue_title

$issue_body
EOF

set +e
( cd "$worktree" && cat task.md | ~/.local/bin/claude-subagent -p --permission-mode bypassPermissions --output-format text ) > "$log" 2>&1
run_status=$?
set -e

pr_url=$(gh pr list --repo "$GH_REPO" --state open --head "$branch" --json url -q '.[0].url' 2>/dev/null || true)

if [ -n "$pr_url" ]; then
  gh issue edit "$issue_num" --repo "$GH_REPO" --remove-label Build 2>/dev/null || true
  ~/.local/bin/notify-tony "Issue #$issue_num klar: $pr_url" "Tempmail build" 2>/dev/null || true
else
  ~/.local/bin/notify-tony "Issue #$issue_num: DeepSeek körde men öppnade ingen PR (status $run_status). Se $log" "Tempmail build - fel" 2>/dev/null || true
fi
