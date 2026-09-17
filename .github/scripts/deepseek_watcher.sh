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
# Build-märkt issue plockades upp. Öppnar en PR mot BASE_BRANCH och rör
# aldrig merge-knappen; en människa granskar och mergar.
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
4. Mergea INTE PR:en själv - en människa granskar och mergar.

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
