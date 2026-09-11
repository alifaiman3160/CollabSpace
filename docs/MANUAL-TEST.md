# CollabSpace — Manual Test Checklist

Everything in this file is something the automated suite **cannot** reach. The
250 automated tests cover routing, authorisation, validation, scoring,
sanitisation and every controller and Livewire action. What they cannot do is
drive a pointer, run JavaScript, or watch a page change on its own.

So this list is deliberately narrow: browser behaviour, and nothing else.

Run it in a real browser before the UAT session and before taking Chapter 4
screenshots.

---

## Before you start

```bash
php artisan migrate:fresh --seed     # rebuilds the demo data
npm run build                        # or: npm run dev
php artisan serve
```

### If you get "Unknown database 'collabspace_v2'"

Nothing is lost. This machine has **two XAMPP installations**, and CollabSpace's
data lives in `C:\xampp` only:

| | `C:\xampp` | `C:\xampp-repair` |
|---|---|---|
| Port | **3306** | 3307 |
| collabspace_v2 | yes | no |
| Other databases | bloodlink, mypetakom, projectsdw, scamshield_my, sdd, sddproject, wordpress, mydatabase, collabspace, hrms | collabspace, hrms |

Both used to sit on 3306, so whichever started first grabbed the port — and if
that was `xampp-repair`, every page 500s on the first query. `xampp-repair` has
since been moved to **3307** (backup at `my.ini.bak`), so the two can run at the
same time and 3306 always belongs to `C:\xampp`.

If it still happens, check which one is actually serving:

```bash
php artisan tinker --execute="echo DB::selectOne('SHOW VARIABLES LIKE \"datadir\"')->Value;"
```

It must print `C:\xampp\mysql\data\`. If it prints the repair path, stop that
process and start MySQL from **`C:\xampp\xampp-control.exe`** — not the
`xampp-repair` control panel.

Every account uses the password **`password`**.

| Role | Email | Notes |
|---|---|---|
| Administrator | `admin@collabspace.test` | |
| Lecturer | `siti.rahayu@collabspace.test` | SECJ3104, owns Groups A and B |
| Lecturer | `chee.wei@collabspace.test` | SECV2113, owns Group 1 |
| Student | `alif.aiman@student.collabspace.test` | top contributor, Group A |
| Student | `farah.aqilah@student.collabspace.test` | the flagged free rider |
| Student | `syafiq.idris@student.collabspace.test` | never signed in — forced password change |

Keep the browser console open. Any red error is a failure even if the page
looks right.

---

## 1. Task board — drag and drop

Sign in as **alif.aiman**, open **Task board**.

| # | Check | Expected |
|---|---|---|
| 1.1 | Drag a card from To Do onto In Progress | Card moves; column counts change; no page reload (the URL bar does not flicker) |
| 1.2 | While dragging, hover over a column | The target column's border darkens |
| 1.3 | Drop a card outside any column | Nothing happens; card returns to where it was |
| 1.4 | Drag a card into **Done** | Hero percentage and the progress bar both increase |
| 1.5 | Drag that same card back to In Progress | Percentage drops back; card keeps its position in the new column |
| 1.6 | Drag on a touchscreen or tablet | Expected to **fail** — HTML5 drag has no touch support. The status dropdown (§2) is the supported path |
| 1.7 | Refresh after a drag | The card is still where you dropped it |

## 2. Task board — the accessible path

The status dropdown is the real control; drag is the accelerator.

| # | Check | Expected |
|---|---|---|
| 2.1 | Change a card's status dropdown from To Do to Done | Card moves columns immediately, no reload |
| 2.2 | Reach the dropdown with `Tab` and change it with the keyboard only | Works identically to the mouse |
| 2.3 | Set a Done card back to To Do via the dropdown | The "Done" count decreases |
| 2.4 | With a screen reader, focus a card's dropdown | It is announced with the task title |

## 3. Task board — create and delete

| # | Check | Expected |
|---|---|---|
| 3.1 | Click the **+** in the hero panel | The new-task form opens below |
| 3.2 | Submit with an empty title | Inline "title is required"; form stays open |
| 3.3 | Create a task with an assignee and a **past** deadline | Card appears in To Do with a red left edge and an **Overdue** pill |
| 3.4 | Create a task with a future deadline | No overdue pill |
| 3.5 | Click the bin icon on a card | Confirmation dialog appears; the card is still there behind it |
| 3.6 | Press `Esc` in that dialog | Dialog closes, nothing deleted |
| 3.7 | Click Cancel | Same — nothing deleted |
| 3.8 | Click **Delete task** | Card disappears without a reload |

## 4. Group chat — polling

Needs **two browsers** (or one normal, one private window).

| # | Check | Expected |
|---|---|---|
| 4.1 | Sign in as alif in one, nurul in the other; both open Group A chat | Both show the same thread |
| 4.2 | Send a message as nurul; watch alif's window without touching it | The message appears **within about 5 seconds** |
| 4.3 | Send as alif | Thread scrolls to the newest message automatically |
| 4.4 | Scroll up to read old messages, then wait through a poll | The view does **not** jump to the bottom — polling must not steal your place |
| 4.5 | Open the chat fresh | It opens scrolled to the newest message, not the oldest |
| 4.6 | Check the day dividers | Today / Yesterday / an explicit date, in that order |
| 4.7 | Compare your own messages with others' | Yours: dark bubble, right-aligned, labelled "You". Others': light bubble, left-aligned, their name |

## 5. Group chat — the file upload widget

| # | Check | Expected |
|---|---|---|
| 5.1 | Click the paperclip and choose a PDF | Filename chip appears above the composer; "Uploading…" shows briefly |
| 5.2 | Click **Remove** on the chip | Chip disappears; no message sent |
| 5.3 | Send a file with no message text | Message shows the file card only |
| 5.4 | Send a file **with** text | Both appear in one bubble |
| 5.5 | Try a `.exe` or `.txt` | Rejected with "That file type is not allowed" |
| 5.6 | Rename a `.txt` to `.pdf` and send it | Still rejected — contents are checked, not just the name |
| 5.7 | Try a file over 10 MB | Rejected with "Files are capped at 10 MB" |
| 5.8 | Click a file card in the thread | Downloads with its **original** filename |
| 5.9 | Copy the download URL, open it in a private window | Redirects to sign-in |
| 5.10 | Sign in there as **nur.alia** (not in Group A) and reopen that URL | 404 |
| 5.11 | Send an image | Renders as a thumbnail — see 5e |

## 5b. Group chat — appearance and grouping

| # | Check | Expected |
|---|---|---|
| 5b.1 | Look at the thread as a whole | Sits on a soft panel with a visible border, white bubbles on top — the same treatment as the workspace and document cards |
| 5b.2 | Short messages | Bubble hugs the text; it does not stretch across the full row |
| 5b.3 | Long message | Wraps and stops at roughly two-thirds width, never edge to edge |
| 5b.4 | Two messages from the same person a minute apart | Second one has **no** repeated avatar or name — it tucks under the first |
| 5b.5 | Same person after a long gap, or a different person | Avatar and name reappear |
| 5b.6 | Date dividers | A small grey chip centred in the thread, WhatsApp style. No hairline rules running across to the edges |
| 5b.6a | Divider wording | `Today`, `Yesterday`, then the short date (`3 Sep 2026`). Hover a timestamp for the full date |
| 5b.7 | Vertical rhythm | Consecutive messages sit close together; separate turns have more air |
| 5b.8 | Student in two or more groups | A **Your groups** pill row sits above the thread, one pill per group showing subject code and group name; the current one is dark. A student in a single group sees no switcher |

## 5c. Group chat — per-sender colour

| # | Check | Expected |
|---|---|---|
| 5c.1 | Open Group A as **alif** | Nurul, Wei Jie and Farah each have a different name and avatar colour |
| 5c.1a | Read the message text itself | The body text carries the sender's colour too, not only the name above it |
| 5c.2 | Your own messages | Dark ink bubble, white text, name shown as "You" — no per-person colour, which would fail contrast on the dark bubble |
| 5c.3 | Sign out, sign back in, reopen | Everyone is the same colour as before |
| 5c.4 | Open the same group as **nurul** | Each person is the same colour they were in alif's view |
| 5c.5 | Read a coloured name **and body** against the white bubble | Comfortably readable — all eight palette entries clear WCAG AA at body size |
| 5c.6 | Compare with the workspace and reports screens | Avatars there stay periwinkle; only chat is colour-coded |

## 5d. Group chat — emoji picker

| # | Check | Expected |
|---|---|---|
| 5d.1 | Click the 🙂 button beside the paperclip | Popover opens above the composer with a small grid |
| 5d.2 | Type "Nice work", put the caret between "Nice" and "work", pick an emoji | Inserted **at the caret**, not appended at the end |
| 5d.3 | After inserting | Caret sits just after the emoji, ready to keep typing |
| 5d.4 | Send the message | Emoji appears in the thread exactly as picked |
| 5d.5 | Reload the page | Emoji still correct — not `?` or a black diamond |
| 5d.6 | Send an emoji-only message with no text | Allowed, and it scores as a normal message |
| 5d.7 | Click outside the popover, then press `Esc` with it open | Closes both ways |
| 5d.8 | Paste an emoji from the OS picker (Win + .) | Works — the picker is a convenience, not the only route |

## 5e. Group chat — inline images and the lightbox

| # | Check | Expected |
|---|---|---|
| 5e.1 | Send a `.png` or `.jpg` | Renders as a **thumbnail** in the bubble, with filename and size beneath |
| 5e.2 | Send a `.pdf` or `.zip` | Keeps the plain file row with a Download link — no thumbnail |
| 5e.3 | Click a thumbnail | Lightbox opens over the page with the image at full size |
| 5e.4 | Click the dark area outside the image | Closes |
| 5e.5 | Press `Esc` | Closes |
| 5e.6 | Click **Download** in the lightbox | Downloads with the original filename |
| 5e.7 | Copy the thumbnail's image URL, open it in a private window | Redirects to sign-in — the image is not public |
| 5e.8 | Sign in there as **nur.alia** (not in Group A), reopen that URL | 404 |
| 5e.9 | Take a pdf's download URL and add `?inline=1` | Still downloads as an attachment — only png/jpg may render inline |
| 5e.10 | Send a very tall or very wide image | Thumbnail is capped in height; the lightbox fits it to the window |

### Re-checking the member palette

`tailwind.config.js` promises every `member.N` clears WCAG AA on the thread
surface, the bubble surface and its own tint. If you change a colour, prove it
still does:

```bash
php -r '
function lum($h){$h=ltrim($h,"#");$c=[];foreach([0,2,4] as $i){$v=hexdec(substr($h,$i,2))/255;
$c[]=$v<=0.03928?$v/12.92:pow(($v+0.055)/1.055,2.4);}return 0.2126*$c[0]+0.7152*$c[1]+0.0722*$c[2];}
function r($a,$b){$x=lum($a);$y=lum($b);return round((max($x,$y)+0.05)/(min($x,$y)+0.05),2);}
foreach ([["#4a5bd0","#e7ebfc"],["#0f6b6b","#d9eeee"],["#7b3f8f","#f0e4f4"],["#a1521f","#f7e6db"],
          ["#3f5163","#e6ebf0"],["#b7291f","#fdeae8"],["#1b6b45","#e6f4ec"],["#6b6414","#f2f0dc"]] as $i => [$fg,$bg]) {
  printf("member-%d  soft %-5s white %-5s tint %-5s  %s
", $i+1, r($fg,"#f7f7fa"), r($fg,"#ffffff"), r($fg,$bg),
    (r($fg,"#f7f7fa")>=4.5 && r($fg,"#ffffff")>=4.5 && r($fg,$bg)>=4.5) ? "PASS" : "FAIL");
}'
```

Every row must say PASS.

## 6. Document editor — the rich-text toolbar

Open **Documents → Chapter 1 - Introduction**.

| # | Check | Expected |
|---|---|---|
| 6.1 | Select text, click **B** | Text turns bold |
| 6.2 | Same with **I** and **U** | Italic and underline |
| 6.3 | Click **H2**, then **H3**, then **Text** on a line | Line changes size each time |
| 6.4 | Click the bullet-list button | Line becomes a bulleted item; `Enter` makes another; `Enter` on an empty item exits the list |
| 6.5 | Same with the numbered list | Numbering renders 1, 2, 3 |
| 6.6 | Click the quote button | Line indents with a left rule |
| 6.7 | Select formatted text, click **Clear** | Formatting is removed, text stays |
| 6.8 | Copy formatted text from Word or a web page and paste it | Pastes as **plain text** — no colours, no fonts |
| 6.9 | Save, then reopen | Formatting is exactly as you left it |
| 6.10 | Save again without editing | "No changes to save." No new version in the history |
| 6.11 | Make one edit and save | "Saved as version N." Version count goes up by one |

## 7. Document version history

| # | Check | Expected |
|---|---|---|
| 7.1 | Click **Version history** | Panel slides in beside the editor |
| 7.2 | Click **View** on an old version | Read-only page; **no** editable area anywhere |
| 7.3 | Try to type in the version body | Nothing happens |
| 7.4 | Click **Restore**, then Cancel | Nothing changes |
| 7.5 | Click Restore and confirm | Body reverts; history gains a version rather than losing one; the body you had before is still listed |

## 8. Confirmation dialogs

These are the ones that were silently broken once. Check every one actually
opens — a dialog that fails to appear means the action fires unguarded.

| # | Where | Expected |
|---|---|---|
| 8.1 | Admin → Users → **Reset password** | Dialog with the person's name in the body text |
| 8.2 | Admin → Users → **Deactivate** | Dialog naming the person |
| 8.3 | Admin → Users → own row | Deactivate is disabled with a tooltip |
| 8.4 | Lecturer → Subjects → **Delete** | Dialog listing the cascade: groups, memberships, tasks, documents, messages |
| 8.5 | Lecturer → Groups → **Delete** | Same, with that group's real counts |
| 8.6 | Lecturer → Group detail → **Remove** a member | Dialog naming the student |
| 8.7 | Any dialog | `Esc` closes it; clicking the dark backdrop closes it; Cancel closes it — none of them perform the action |
| 8.8 | Any dialog | The body text is a real sentence, **not** the literal text `@js(...)` |

## 9. Notifications and the live counter

| # | Check | Expected |
|---|---|---|
| 9.1 | Sign in as farah; look at the sidebar | Red count badge on **Notifications** |
| 9.2 | Open Notifications, click **Mark read** on one | Row dims; **sidebar count drops immediately, without a reload** |
| 9.3 | Click **Mark all read** | Count badge disappears from the sidebar at once |
| 9.4 | Click **Mark unread** on a row | Count returns |
| 9.5 | Filter to **Unread** | Only unread rows; counts in the tab labels are right |
| 9.6 | Remove a single notification | Row disappears, no reload |
| 9.7 | **Clear all** | Browser confirm appears first; then the list empties |
| 9.8 | Two windows: alif and nurul, both on any page. As nurul, send a chat message in Group A | Alif's sidebar counter increases **on its own within about 15 seconds** |
| 9.9 | Click **Open** on a notification | Goes to the right screen and marks it read |

## 10. The notification writers

Each of these should produce a notification for someone else, and none for you.

| # | Do this | Expected |
|---|---|---|
| 10.1 | Create a task assigned to a group mate | They get "assigned you: …"; you get nothing |
| 10.2 | Create a task assigned to yourself | Nobody is notified |
| 10.3 | Send a chat message | Every other member gets one; you get none |
| 10.4 | Create a document | Every other member notified |
| 10.5 | Edit a document and save a real change | Every other member notified |
| 10.6 | Save a document with no change | **Nobody** notified |
| 10.7 | As a lecturer, add a student to a group | That student is notified |
| 10.8 | As a lecturer, remove a student | That student is notified |

## 11. Account email

| # | Check | Expected |
|---|---|---|
| 11.1 | As admin, create a user | Green banner says the details were emailed to that address |
| 11.2 | `tail -n 120 storage/logs/laravel.log` | The email is there: From CollabSpace, correct To, subject "Your CollabSpace account is ready" |
| 11.3 | Read the logged email | Contains the person's name, email, temporary password, role, and the sign-in link |
| 11.4 | Sign in as that new user with the password from the email | It works, and you are held on the change-password screen |
| 11.5 | Change the password there | You land on the dashboard for that role |
| 11.6 | Sign in again with the old temporary password | Rejected |

## 12. Sign-in and sign-out

| # | Check | Expected |
|---|---|---|
| 12.1 | Sign-in page narrower than ~768px | The right-hand marketing panel is hidden; the form stays usable |
| 12.2 | Click the eye icon in the password field | Password becomes visible; click again to hide |
| 12.3 | "Keep me signed in" checkbox | Tick mark appears |
| 12.4 | **Sign out** in the sidebar, on every role | Signs out and returns to the sign-in page — not a 405 error |
| 12.5 | Sign in as **syafiq.idris** | Forced straight to the change-password screen |
| 12.6 | From there, try to reach `/student/dashboard` by URL | Bounced back to change-password |

## 13. Shell, layout and responsiveness

| # | Check | Expected |
|---|---|---|
| 13.1 | Every sidebar link, on all three roles | All load a real screen; none are dead |
| 13.2 | Current page's nav item | Dark pill, clearly the active one |
| 13.3 | Narrow the window to tablet width | Tables scroll sideways inside their own box; the page itself does not scroll sideways |
| 13.4 | Task board on a narrow window | Columns scroll horizontally; cards stay readable |
| 13.5 | Zoom to 150% | Nothing overlaps or is cut off |
| 13.6 | Check the browser tab title on several screens | Each is specific, not just "CollabSpace" |

## 14. Screenshot pass for Chapter 4

Not pass/fail — this is the sweep to do before capturing.

| # | Screen | Look for |
|---|---|---|
| 14.1 | Lecturer dashboard | 5 stat cards populated; the groups table shows real progress bars |
| 14.2 | Contribution report, Group A | Farah flagged; threshold and average both visible; bars comparing members |
| 14.3 | CSV export | Opens cleanly in Excel; names not mangled |
| 14.4 | Student task board | All three columns occupied; at least one overdue card visible |
| 14.5 | Group chat | Thread reads as a real conversation; day dividers present; attachments visible |
| 14.6 | Document editor | Formatted body; version history open beside it |
| 14.7 | Admin users | Mixed roles and statuses on screen |
| 14.8 | Any screen | No lorem ipsum, no "Test User", no placeholder text anywhere |

---

## Recording results

Note the check number, the browser and version, and what actually happened.
Anything that fails and is **not** already listed as expected (1.6 is the only
known expected failure) is a defect — capture the console output with it.
