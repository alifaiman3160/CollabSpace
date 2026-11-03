# CollabSpace — Manual Test Checklist

Everything in this file is something the automated suite **cannot** reach. The
250 automated tests cover routing, authorisation, validation, scoring,
sanitisation and every controller and Livewire action. What they cannot do is
drive a pointer, run JavaScript, or watch a page change on its own.

So this list is deliberately narrow: browser behaviour, and nothing else.

Run it in a real browser before the UAT session and before taking Chapter 4
screenshots. Before any destructive step (a migration, a purge), take a backup: see [BACKUP.md](BACKUP.md).

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

## 1b. Task board — whose card is whose

Sign in as **alif.aiman**, open **Task board** for Group A.

| # | Check | Expected |
|---|---|---|
| 1b.1 | Look at a card assigned to someone else | A small padlock beside their name; hovering says only they can move it |
| 1b.2 | Look at a card assigned to you | Reads "You", no padlock |
| 1b.3 | Drag someone else's card to another column | It lifts and drops, then snaps back. A red toast names the owner and says to reassign first |
| 1b.4 | Change that same card's status with the dropdown | Same refusal, same toast. The dropdown returns to where it was |
| 1b.5 | Drag your own card | Moves normally, no toast |
| 1b.6 | Drag an unassigned card | Moves normally — nobody owns it, and you are credited if you drop it into Done |
| 1b.7 | Edit someone else's card, reassign it to yourself, then drag it | Now it moves. This is the way round a member who has gone quiet |
| 1b.8 | Sign in as that other member and check their contribution | Unchanged by anything you attempted above |

1b.8 is the point of the whole rule: before it, two drags could hand a
groupmate credit they had not earned, or delete credit they had.

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
| 5c.2 | Your own messages | Dark ink bubble with white **body** text. The name "You" above it carries **your own colour**, the same hue you appear as to everyone else — the name sits on the thread surface, not on the dark bubble, so the contrast rule only applies to the body |
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
| 5e.1 | Send a `.png` or `.jpg` | Renders as a **thumbnail** and nothing else. **No filename, no size** under it — the bubble hugs the picture |
| 5e.1a | Open the lightbox on it | The filename and size are there, in the bar under the full-size image |
| 5e.2 | Send a `.pdf` or `.zip` | Keeps the plain file row **with** its name and size and a Download link — there is no preview to speak for it |
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

## 6b. Document editor — the word-processor controls

Same document. Each of these is a control the sanitizer has an explicit rule
for, so the real check in every row is 6b.15: save, reopen, and see it survive.
Formatting that disappears on save means the toolbar and the allowlist have
drifted apart.

| # | Check | Expected |
|---|---|---|
| 6b.1 | Select a line, pick **Font → Georgia** | Text changes face. Try each font in the list |
| 6b.2 | Select text, pick **Size → Large**, then **Small** | Text grows and shrinks |
| 6b.3 | Put the cursor in a paragraph, pick **Spacing → Double** | That paragraph's lines spread apart. Other paragraphs are untouched |
| 6b.4 | Select text, click a colour swatch | Text takes that colour |
| 6b.5 | Click the pencil beside the swatches, pick any colour | Native colour picker; the choice applies |
| 6b.6 | Select text, click a highlight swatch | Background behind the text changes |
| 6b.7 | Click **None** in the highlight group | Highlight is removed |
| 6b.8 | Click each of the four alignment buttons on a paragraph | Left, centred, right, justified |
| 6b.9 | Click **S** on selected text | Strikethrough |
| 6b.10 | Click the table button | A three-column table with a header row appears, with a paragraph after it so you can keep typing |
| 6b.11 | Type into the table cells, press Tab | Moves between cells |
| 6b.12 | Make several changes, then click **Undo** repeatedly | Each change is reversed in order |
| 6b.13 | Click **Redo** | The undone changes come back |
| 6b.14 | Type a sentence | The word and character counts at the bottom of the editor rise **as you type**, not on save |
| 6b.15 | Delete a word | Both counts fall. Empty document reads 0 words, 0 characters |
| 6b.16 | Apply every control above, save, reopen | All of it is exactly as you left it |

Narrow screen:

| # | Check | Expected |
|---|---|---|
| 6b.17 | Resize the window to phone width, or open on a phone | The toolbar **wraps onto more rows**. It never scrolls sideways and no button is cut off |
| 6b.18 | At that width, use the font and size dropdowns | Both open and apply normally |

What must **not** be there:

| # | Check | Expected |
|---|---|---|
| 6b.19 | Look for page breaks, headers and footers, page numbers, margins | None of them exist. The editor is a continuous flow, not a paginated page. Say so in the report rather than logging it as missing |

## 6c. Document editor — what the sanitizer refuses

The automated suite covers injection for every permitted style property. These
two are worth doing by hand because they are what a marker is most likely to try.

| # | Check | Expected |
|---|---|---|
| 6c.1 | Copy a styled table from a web page and paste it | Arrives as **plain text**. No table, no colours, no fonts |
| 6c.2 | Format text heavily, save, then read the stored row with `php artisan tinker --execute="echo App\Models\Document::find(1)->content;"` | Only font-family, font-size, line-height, color, background-color and text-align appear. No `url(`, no `position`, no `class`, no `id` |

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

Run this first on the default `log` mailer, where the email is written to the
log instead of sent. Section 11b turns on real delivery.

| # | Check | Expected |
|---|---|---|
| 11.1 | As admin, create a user | A green toast says the details were emailed to that address |
| 11.2 | Look at the whole admin screen | The temporary password is **nowhere on it**. It belongs to one person and reached them by email |
| 11.3 | `tail -n 120 storage/logs/laravel.log` | The email is there: From CollabSpace, correct To, subject "Your CollabSpace account is ready" |
| 11.4 | Read the logged email | Contains the person's name, email, temporary password, role, and the sign-in link |
| 11.5 | Sign in as that new user with the password from the email | It works, and you are held on the change-password screen |
| 11.6 | Change the password there | You land on the dashboard for that role |
| 11.7 | Sign in again with the old temporary password | Rejected |
| 11.8 | Reset a user's password from the admin screen | A green toast; no password on screen; a "Your CollabSpace password has been reset" email carrying the new one |
| 11.9 | Break `MAIL_PASSWORD`, clear config, then create a user | A red panel saying the email failed, showing the password once so it can be handed over. This is the only case the admin sees it |
| 11.10 | Reload that page | The panel is gone and the password cannot be looked up again — reissue it instead |

## 11b. Real mail delivery over Gmail

Do this **before** the UAT session, not during it. Two flows are useless if mail
does not leave the machine: a new account's temporary password only reaches its
owner by email, and a forgotten password can only be reset through an emailed
link. Both fail quietly on the `log` mailer — the account still gets created,
the reset link still "sends", and nothing arrives.

### Set it up

You need a Gmail account with **2-Step Verification switched on**, because
Google only offers App Passwords on accounts that have it.

1. Google Account → Security → 2-Step Verification. Turn it on if it is off.
2. Same page → **App passwords** → create one named `CollabSpace`.
   Google shows a 16-character code **once**, as four groups of four.
3. In `.env`:

```
MAIL_MAILER=smtp
MAIL_USERNAME=your.address@gmail.com
MAIL_PASSWORD=the sixteen character app password
MAIL_FROM_ADDRESS="your.address@gmail.com"
```

4. `php artisan config:clear`

> **The App Password is not the account password.** Google stopped accepting
> account passwords over SMTP in 2022. Using the real one fails with
> "Username and Password not accepted" however correctly it is typed — see 11b.4.

`MAIL_HOST`, `MAIL_PORT` and `MAIL_SCHEME` are already correct for Gmail and do
not need touching. Every one of these keys is read from `.env` and nowhere else;
nothing about delivery is hardcoded.

### Verify it

| # | Check | Expected |
|---|---|---|
| 11b.1 | `php artisan mail:test you@example.com` while still on `MAIL_MAILER=log` | Warns "nothing was sent over the network" and tells you how to switch. **Not** a success message |
| 11b.2 | Switch to `smtp`, clear config, run it again | Settings table shows smtp.gmail.com, port 587, scheme smtp, your username, and the password as dots plus its length. Then "Sent." |
| 11b.3 | Check the inbox, including **spam** | Message arrives. A first message from a new sender very often lands in spam — say so in the report rather than calling it a failure |
| 11b.4 | Deliberately put the account password in `MAIL_PASSWORD`, clear config, run again | Fails with Google's own 535 response, and the command explains the App Password. Put the App Password back afterwards |
| 11b.5 | Deliberately set `MAIL_PORT=465` while leaving `MAIL_SCHEME=smtp`, run again | Fails or hangs then fails; the command explains the port/scheme pairing. Put 587 back |
| 11b.6 | With delivery working, create a user account as admin | The account email arrives at the real address, with CollabSpace branding and no Laravel logo |

If the password shows the wrong length in 11b.2, it is the wrong value —
an App Password is 16 characters, or 19 with the spaces Google displays.

## 11c. Forgot password, end to end

Run the whole round trip on **real** delivery, with a real inbox.

| # | Check | Expected |
|---|---|---|
| 11c.1 | Sign-in screen → "Forgot password" | The CollabSpace screen, not stock Breeze: heading "Forgot password", one email field, one dark pill button |
| 11c.2 | Submit an address that has no account | Error on the field saying no user matches. No email sent |
| 11c.3 | Submit a **deactivated** account's address | Error: "This account has been deactivated. Ask your administrator to reactivate it." No email sent |
| 11c.4 | Submit a live account's address | Green status says the link has been emailed |
| 11c.5 | Open the email | Subject "Reset your CollabSpace password"; greets the person by name; CollabSpace wordmark at the top; no Laravel logo; no "Hello!" |
| 11c.6 | Read the body | States the 60-minute expiry and says what to do if it was not them |
| 11c.7 | Click the button | Lands on the CollabSpace "New password" screen with the email prefilled |
| 11c.8 | Submit two passwords that do not match | Validation error, no reset |
| 11c.9 | Submit a matching pair | Redirected to sign-in with a success message |
| 11c.10 | Sign in with the new password | Works, and lands on the dashboard — **not** the forced-change screen, even if the account was still on a temporary password |
| 11c.11 | Click the same link in the email a second time | Rejected: the token is spent |
| 11c.12 | Ask for a link, then deactivate that account, then open the link | Rejected with the deactivated message. The password does not change |
| 11c.13 | Break `MAIL_PASSWORD`, clear config, then ask for a link | A red message saying the link could not be sent — **not** a 500 page, and **not** a green "link sent" |

11c.10 and 11c.12 are the two that used to be wrong, so do not skip them.

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

## 13b. Deadline reminders — the scheduled pass

These are the only notifications no click produces: a deadline passes because
time moves. The automated suite covers the logic, so what is left to check by
hand is that the schedule actually runs on this machine — a command nothing
invokes is the same as no feature at all.

Run it directly first, as a student with dated tasks on the board:

```bash
php artisan collabspace:deadline-reminders --dry-run
php artisan collabspace:deadline-reminders
```

| # | Check | Expected |
|---|---|---|
| 13b.1 | `--dry-run` | Prints the table of tasks; writes nothing — the bell does not move |
| 13b.2 | Real run | Reports how many were sent; the student's bell count rises |
| 13b.3 | Notifications page | "Due today/tomorrow" rows in periwinkle, "Overdue" rows in red |
| 13b.4 | Run it a second time | "Nothing new to announce" — **no** duplicate rows appear |
| 13b.5 | Sign in as the lecturer who owns that subject | One overdue row per late task, linking to Monitor progress |
| 13b.6 | `php artisan schedule:list` | Shows `collabspace:deadline-reminders`, next due 07:00 |
| 13b.7 | The seeded SECJ3104 project is 12 days out | Nothing yet — the project lead time is 7 days |
| 13b.8 | Edit SECJ3104, move the deadline to 3 days away, run again | Every student in the subject gets one "due in 3 days" row |
| 13b.9 | Run again | No second project row for anyone |

## 13d. Project brief and project deadline

| # | Check | Expected |
|---|---|---|
| 13d.1 | Lecturer → My subjects → edit SECJ3104 | Brief textarea and project deadline both filled from the seed |
| 13d.2 | Student dashboard | No brief panel. Each subject card carries a small chip like "Due 14 Nov · 12 days left" (warning colour at 3 days or fewer, red once past, nothing without a deadline). Subjects with a group come first, "No group yet" ones after; two columns, three with more than four subjects; a card with no announcements has no strip, with some it shows the latest title and "+N more" |
| 13d.3 | Student workspace and task board for Group A | A closed card "Project brief" under the group cards with the due date and countdown in its header; click it and the brief opens smoothly. Only its own subject's brief |
| 13d.4 | SECV2113 (no brief, no deadline) | No panel at all — not an empty box saying "no brief" |
| 13d.5 | Set the deadline to yesterday | Panel turns red; reads "Overdue by a day" |
| 13d.6 | Clear both fields and save | Panel disappears for the students again |

On a server this needs one cron entry, or the schedule never fires:

```
* * * * * cd /path/to/collabspace && php artisan schedule:run >> /dev/null 2>&1
```

On Windows, a Task Scheduler entry running `php artisan schedule:run` every
minute. For the UAT session it is enough to run the command by hand — say so in
the report rather than implying a cron was in place.

## 13c. Lecturer reading group documents

| # | Check | Expected |
|---|---|---|
| 13c.1 | Monitor progress → Documents panel | Lists the group's documents with version counts |
| 13c.2 | Open one | Body renders formatted; "Read only" badge; no editor, no save button |
| 13c.3 | Open a past version from the history | That version's body; the entry highlighted in the list |
| 13c.4 | Try another lecturer's group by editing the id in the URL | 404 |

## 13e. Success messages and confirmations

The tests can see that a message was rendered. They cannot watch it slide in,
time out, or pause when the pointer rests on it, so check those here.

| # | Check | Expected |
|---|---|---|
| 13e.1 | As admin, create a user | A toast slides down from the top right naming the person. The temporary password panel stays in the page, and **no** toast duplicates it |
| 13e.2 | Watch the toast without touching it | It fades out on its own after a few seconds |
| 13e.3 | Trigger one, then rest the pointer on it | The countdown stops; it stays until the pointer leaves |
| 13e.4 | Trigger one, press Escape | It dismisses |
| 13e.5 | Try to deactivate your own admin account | A red toast, same shape as the green one |
| 13e.6 | Save anything on a long screen, scrolled to the bottom | The toast is still visible — it is fixed to the viewport, not the page |
| 13e.7 | Create a subject, a group, a task, a document | Each reports itself. None of them are silent |

Destructive actions:

| # | Check | Expected |
|---|---|---|
| 13e.8 | Lecturer → My subjects → Delete | The CollabSpace dialog, listing what the cascade will take |
| 13e.9 | Try to press the delete button straight away | It is disabled. The subject code must be typed first |
| 13e.10 | Type the code with a wrong letter | Still disabled |
| 13e.11 | Type it exactly, then delete | It goes through, and a toast confirms it |
| 13e.12 | Open the dialog again on another subject | The typed box is empty, not carrying the last value |
| 13e.13 | Groups → Delete | Same, keyed on the group name |
| 13e.14 | Remove a student from a group | A dialog, but no typing — it is a one-click thing to undo |
| 13e.15 | Notifications → Clear all, and the bin on one row | The CollabSpace dialog, **not** the grey browser box with the hostname in it |

## 13f. CGPA, and forming groups automatically

Run `php artisan migrate:fresh --seed` first. That adds sixteen students with
CGPAs (all but Syafiq, deliberately), six of them with no group at all, and three
groups of different origins: A by hand, B balanced, 1 imported.

Sample files are in `docs/samples/`:

| File | What it exercises |
|---|---|
| `group-form-clean.csv` | Two clean groups; three students left over |
| `group-form-problems.csv` | Every problem the review screen catches |
| `group-form-per-student.csv` | The one-row-per-student layout |

### CGPA

| # | Check | Expected |
|---|---|---|
| 13f.1 | Admin → edit a student | A **CGPA (optional)** box, with a note that it is entered by hand and never read from an academic system |
| 13f.2 | Enter `3,47` (comma) and save | Saved as 3.47 |
| 13f.3 | Enter `4.5`, then `3.456`, then `abc` | Each refused with its own message. Nothing is saved |
| 13f.4 | Clear the box and save | Stored as *none*. Check it did **not** become 0.00 |
| 13f.5 | Switch the role to Lecturer | The CGPA box disappears and the figure is dropped |
| 13f.6 | Sign in as **chee.wei** (who teaches SECV2113, where Syafiq sits) → Students | A CGPA column. Syafiq reads **Not recorded**, not 0.00. A footnote says it is entered by hand |
| 13f.7 | Sign in as **alif.aiman** and look at the dashboard, workspace, board, chat, documents, account | **No CGPA anywhere**, his own included |

### Mode 1 — balanced allocation

Sign in as **siti.rahayu**. Groups → choose SECJ3104 → **Auto-assign students**.

| # | Check | Expected |
|---|---|---|
| 13f.8 | Look at the hub | Two stat cards (nine without a group, eight with a CGPA), one small line about existing groups, and two equal cards side by side, each with a title, one sentence and one button. The balance form is hidden until **Balance by CGPA** is clicked. **Good to know** is collapsed and holds the CGPA-by-hand, no-enrolment and existing-groups notes |
| 13f.9 | Untick two students | The "number of groups" hint updates live, and the box clamps into the allowed range |
| 13f.10 | Put 2 groups with all nine students, then **Preview** | Refused: "9 students with a limit of 4 per group need between 3 and 4 groups" |
| 13f.11 | Preview with 3 groups | Three cards, each with its average CGPA, member list and a bar. A banner says **nothing has been saved** |
| 13f.12 | While on the preview, open Groups in the sidebar | **No new groups.** Previewing creates nothing |
| 13f.13 | Check the spread figure | Close to zero. Averages differ by a few hundredths |
| 13f.14 | Press **Regenerate** several times | A different arrangement most times. Averages stay close. Still nothing in the Groups list |
| 13f.15 | Confirm | Redirected to Groups with a toast. Three new groups labelled **Balanced** |
| 13f.16 | Open one → "How this group was formed" | Balanced by CGPA, the other groups from the same batch as links, the average it was dealt with, and that CGPAs are entered by hand |
| 13f.17 | Sign in as one of the placed students | A notification that they were added to a group |
| 13f.18 | Back in the browser, press Back to the preview and **Confirm** again | An "expired" message. Nothing is created twice |

The uneven headcount:

| # | Check | Expected |
|---|---|---|
| 13f.19 | As **chee.wei**: subject **SECV2113** (limit 3), all thirteen students, 5 groups | Sizes 3, 3, 3, 2, 2, not three of three and the rest dumped in one. The "does not divide evenly" note appears |
| 13f.20 | Make the pool smaller so one student is left over from a full round | The weakest leftover joins the group with the **highest** average, and the spread stays small |
| 13f.21 | Untick everyone but one student | "Select at least two students" |

Things that change while you look:

| # | Check | Expected |
|---|---|---|
| 13f.22 | Preview, then in another tab place one of those students in a group by hand, then Confirm | **Nothing is created**, the preview is discarded, and the message names who was placed |
| 13f.23 | Preview, then in another tab deactivate one of them, then Confirm | Nothing created, and it says why |

### Mode 2 — importing students' own choices

Auto-assign students → **Import from CSV**.

| # | Check | Expected |
|---|---|---|
| 13f.24 | Download both examples | Each opens in Excel with the right headings |
| 13f.25 | Upload `group-form-clean.csv`, layout *one row per group* | Column screen. Group name and the four member columns are pre-selected |
| 13f.26 | Continue | Review: two groups ready, nothing in the problem sections, three students under "never appear" |
| 13f.27 | **Create 2 groups** | Both appear as **Imported**. Their panel names the file and the rows. Redirected to the hub, with an offer to place the three left over |
| 13f.28 | Balance those three | One balanced group. Both labels now sit side by side in the list |

Now the problems. Run `migrate:fresh --seed` again first, then upload
`group-form-problems.csv`.

| # | Check | Expected |
|---|---|---|
| 13f.29 | Look at the summary | Five groups, two ready, three needing attention. "Resolve the problems to import everything" is **disabled** |
| 13f.30 | **Students not found, or who cannot be placed** | `A22EC9999` and `Ahmad Faiz`. The name carries "That looks like a name. Names are never used to match students" |
| 13f.31 | Pick Tan Mei Ling for `A22EC9999`, press **Apply my choices** | It now reads "Matched by you", and she leaves the *never appear* list |
| 13f.32 | Remove `Ahmad Faiz` | Moves to "Removed by you". Untick it and apply: he comes back |
| 13f.33 | **More than one group** section | Hana, in Team Zenith and Team Lyra. Choose where she stays; the problem clears |
| 13f.34 | Same section | Alif is already in Group A. Priya and Kai Ming are already in Group B. Each says which group |
| 13f.35 | **Over the size limit** | Team Zenith, 5 members, limit 4. Remove Alif, or tick *allow this group 5 members* |
| 13f.36 | **Group names** | "Group B - MediTrack" already exists. Rename it in the box beside it |
| 13f.37 | Make a choice, then trigger something that moves its control (rename a group so the problem goes away) and apply | The choice is **still there**. Decisions do not vanish with their control |
| 13f.38 | With problems still unresolved, press Create | Refused. Nothing created |
| 13f.39 | Leave problem groups out | "Or skip the problem groups": it lists which groups are left out, then creates only the ready ones |
| 13f.40 | Check afterwards | The students of the skipped groups simply have no group. The imported groups say problem groups were skipped |
| 13f.41 | Start again, tick **Leave this group out** on a group with problems | The remaining groups can now be imported in full: leaving one out on purpose is a decision, not an unresolved problem |
| 13f.42 | **Discard this import** | Back to the hub. Nothing created |

Other file shapes:

| # | Check | Expected |
|---|---|---|
| 13f.43 | Upload `group-form-per-student.csv` with *one row per student* | Orion's three students (one by matric alone, one by matric and email, one by email alone) become one group, even though the name is typed in different capitals |
| 13f.44 | Same file | Rajesh answered for Vega and for Lyra, so he is flagged as in two groups |
| 13f.45 | Open `group-form-clean.csv` in Excel, Save As → CSV (semicolon or UTF-8 with BOM, whichever your Excel offers), upload that | Reads exactly as before |
| 13f.46 | Upload a PDF renamed to `.csv` | Refused with a plain message, not an error page |
| 13f.47 | Upload an empty file | "That file is empty" |
| 13f.48 | Walk the column screen with no group column ticked | "Mark exactly one column as the group name" |

Ownership:

| # | Check | Expected |
|---|---|---|
| 13f.49 | Sign in as **chee.wei** and paste a SECJ3104 formation URL | 404 |
| 13f.50 | As a student, open `/lecturer/subjects/1/formation` | 403 |

## 13g. Motion: hover, focus and reduced motion

Motion lives in shared components and three tokens in `tailwind.config.js`
(`duration-motion-fast` 120ms, `duration-motion` 150ms, `duration-motion-slow`
180ms, one `ease-motion` curve). Nothing should slide further than 2px.

| # | Do | Expect |
|---|---|---|
| 13g.1 | Hover any button (Add one group, Create, Save) | Rises 1px with a slightly stronger shadow. Click and hold: it presses down slightly. Release: back to rest. |
| 13g.2 | Hover a disabled button | Nothing moves. |
| 13g.3 | Hover a row in any table (Users, Groups, Students) | Background shifts gently. The row does **not** move. |
| 13g.4 | Hover a sidebar item | Background fades in smoothly rather than snapping. |
| 13g.5 | Open a confirm dialog (Users, deactivate), the group-switcher popover, the chat menu, and trigger a toast (save a user) | Each fades in with a small scale-up, about a fifth of a second. Closing is slightly quicker. |
| 13g.6 | Press **Tab** through a page | Every link, button and field shows a visible focus ring. Nothing is only visible on hover: the helper text under Add one group and Auto-assign students is printed on the page as well as in a tooltip. |
| 13g.7 | Student, Task board: drag a card between columns | The card follows the pointer exactly as before. It does not jump, wobble or lift on hover, and the drop lands as normal. |
| 13g.8 | Student, Group chat: leave it open for 15s while another account sends a message | The new message appears without any entrance animation, the scroll position does not twitch, and hover states elsewhere do not flicker on the refresh. |
| 13g.9 | Turn on reduced motion (Windows: Settings, Accessibility, Visual effects, off for Animation effects; macOS: Reduce motion) and reload | Buttons no longer lift or press. Dialogs, menus and toasts appear and vanish instantly with no fade or scale. Colour changes on hover still happen, immediately. |
| 13g.10 | Hover and press the small controls: the All/Unread chips (Notifications), subject chips (Groups), the rich-text toolbar, emoji picker, task edit/delete icons, the log-out icon, the password eye | Colour eases in; pressing nudges the control down slightly. They do not lift. |
| 13g.11 | Run `php scripts/audit-interactions.php` | Reports every control as covered and exits 0. `tests/Feature/InteractionCoverageTest.php` fails if a new button is added without `btn-motion` or `interactive` |
| 13g.12 | Open every table (Students, Groups, Subjects, Monitor, Dashboard overview, Users, Documents, Members, the report breakdown, Open tasks). Resize to 390px wide | Header is small and muted; rows shift background only and never lift; last row has no line under it. Each table scrolls sideways inside its card; the page itself never scrolls sideways. |
| 13g.13 | In Subjects, Users, Groups, Members and Documents, look at the row without hovering | Every action is on screen: workflow ones (Edit, Manage, Open, Groups, Auto-assign students, Reset password, Activate, Report, Monitor) in normal weight, destructive ones (Delete, Remove, Deactivate) quieter, turning red on hover and focus. Nothing appears only on hover. At 390px, Subjects and Users show Edit plus a **More** button; More opens a menu of the rest, closes on Escape, an outside tap or choosing an item, and is reachable by keyboard. |
| 13g.14 | Sign in as a student and open every page | No "Low", "Free rider", "Low participation" or CGPA anywhere. |
## 13h. AI provider (setup only; the marking screens come later)

| # | Do | Expect |
|---|---|---|
| 13h.1 | With `AI_DRIVER=none` (or no key), run `php artisan ai:ping` | Says AI suggestions are switched off, exits cleanly. Nothing is sent. |
| 13h.2 | Set `AI_DRIVER=anthropic` with `ANTHROPIC_API_KEY` empty, run it again | "no API key is set for Anthropic", points at `.env.example`, exits with an error. |
| 13h.3 | Put a real key and model in `.env`, run `php artisan ai:ping` | Prints driver, provider, model and `answered in N ms`. The key is not printed anywhere. |
| 13h.4 | Put a wrong key in `.env`, run it | A short failure with the HTTP status, no key in the text. |
| 13h.5 | Repeat 13h.3 for each provider you have access to: `openai`, `gemini`, `openrouter`, `groq`, `ollama` (no key needed locally) | Same result for each; only the provider and model lines differ. |
| 13h.6 | Open `storage/logs/laravel.log` after 13h.4 | A "AI request failed" line with provider, model and status. No key, no prompt, no student data. |
## 13i. Rubric import (upload and review; suggestions come later)

Run `php artisan migrate` first. Sign in as a lecturer. The sample is `docs/samples/MarkingRubric.xlsx`.

| # | Do | Expect |
|---|---|---|
| 13i.1 | My subjects, a subject's **Rubric** action | The rubric page with an upload box and no versions yet. At 390px the action is under **More**. |
| 13i.2 | Upload the sample | A review screen headed "Nothing is saved yet". Two sections, CLO2: Report Quality (Manual) and CLO4: Teamwork (System-suggested), four criteria each. |
| 13i.3 | Open the rubric page in another tab | Still no versions. Reading the file saved nothing. |
| 13i.4 | Look at the CLO2 "Excellent" level | Marks 5, range 4–5. In CLO4, "Good" is 1.5 and "Excellent" 2. |
| 13i.5 | Rename a section, change a weight to `7,5`, reword a descriptor | Accepted; a comma is fine as the decimal mark. |
| 13i.6 | Change CLO4's section to Manual | Every CLO4 criterion switches to Manual. Set one criterion back to System-suggested. |
| 13i.7 | Delete a criterion, a level, and a whole section | Each disappears. They stay gone after saving. |
| 13i.8 | Empty a criterion name and save | Refused with "Every criterion needs a name." Everything else you typed is still there, and nothing was saved. |
| 13i.8a | On the review screen, look at each section's panel | CLO2 shows declared total 30, maximum 30 with level × weight (35 with level only), and "Level × weight" preselected. CLO4 shows 10, maximum 10 with level only (20 with level × weight), and "Level only" preselected. Both say the maximum matches the declared total. |
| 13i.8b | Change CLO4 to "Level × weight" | The status turns amber: "The maximum is 20 but the declared total is 10." |
| 13i.8c | Edit a CLO4 weight or a level's marks | The maxima update as you type. |
| 13i.8d | Clear the declared total, and set the formula to "Choose…" on CLO4, then save | Refused: "Choose how marks are calculated for “CLO4 – Teamwork (10 marks)” before saving." Setting CLO2 (Manual) to "Choose…" saves fine. |
| 13i.8e | View a saved version | Each section states how its total is reached, e.g. "Marks = level marks × weight; maximum 30; declared total 30." |
| 13i.9 | Save | "Saved as version 1". The rubric page lists it as **Current**. **View** shows exactly what you corrected, not what the file said. |
| 13i.10 | Upload the sample again and save | Version 2 is Current. Version 1 is unchanged, and still viewable. |
| 13i.11 | Delete version 2 | Version 1 becomes Current again. |
| 13i.12 | Upload a .csv, a .pdf, a text file renamed to .xlsx, and a workbook with no level headers | Each is refused in plain words and nothing is saved. |
| 13i.13 | Sign in as another lecturer and open the first lecturer's rubric URL | Not found. As a student or admin: forbidden. |
## 13j. Evidence types and suggestion preview (no marking screens yet)

Run `php artisan migrate`. Sign in as a lecturer with the sample rubric saved (13i). The group ids are in the address of a group's page; the rubric id is in the address of a saved version.

| # | Do | Expect |
|---|---|---|
| 13j.1 | Upload the sample rubric and look at the CLO4 criteria on the review screen | Each criterion has an **Evidence** choice while it is System-suggested. "Contribution to tasks" shows Task completion, "Reliability and deadlines" Reliability (deadlines), "Communication" and "Collaboration" their own, each with "A default chosen from the wording. Change it if it is not right." "Initiative and problem solving" shows "Not set (treated as none)". |
| 13j.1a | On the review screen, look at a CLO4 section | One muted line above its criteria: "Use Manual for criteria about attitude or quality, such as respect or the value of ideas. Recorded activity cannot measure these." It is not repeated on each criterion. |
| 13j.1b | Look at one System-suggested criterion | Under the criterion name, a compact **Evidence** select with six choices, and ONE muted line beneath it describing the choice made now, for example "Share of assigned tasks that are completed". Change the select: the line changes with it. Where the choice is still the default from the wording, "A default chosen from the wording. Change it if it is not right." sits beside the select, and goes once you change it. |
| 13j.1c | Look at the row's shape | The criterion (name, evidence, weight, decided by) is on the left and its performance levels are on the right, starting level with the name, so the row is only as tall as its levels. A Manual criterion shows no Evidence control. |
| 13j.1d | Rename a System-suggested criterion to "Respect for ideas" while Communication is selected | An amber hint appears directly under the select at once: "This looks like a judgement about attitude or quality ... Consider setting the criterion to Manual." Nothing is blocked. Set the criterion to Manual or the evidence to None and it goes. "Meets deadlines" with Task completion gives the Reliability hint; "Communication" with Reliability gives "The wording looks more like Communication ...". |
| 13j.1e | Save and open the version. Resize to 390px | The same compact control, note (once per section that has suggested criteria) and hints, updating as you change a selection. Saving works whatever a hint says. At 390px the select is full width and the levels sit below the criterion. |
| 13j.2 | Set CLO4's section to Manual, then back to System-suggested | The Evidence choices disappear and return. |
| 13j.3 | In a level-only section, change a weight to 3 while the top level is 2 | An amber note appears under that criterion: "The top level is worth 2 but the weight, which is this criterion's maximum, is 3. Check which is right." It clears when they match again. |
| 13j.4 | Save, open the version, change an evidence type and choose **Save changes** | "Evidence types saved." Only evidence changed: weights, levels and descriptors are as they were. The same amber note shows here for a mismatch. |
| 13j.5 | With `AI_DRIVER=none`: `php artisan marking:preview <rubric> <group>` | Per student: a facts line, then per System-suggested criterion the **From the numbers** level with its sentence and the marks it is worth, "AI's view: unavailable. AI suggestions are switched off", and "Needs lecturer input" for a criterion with no evidence type or no data. |
| 13j.6 | Check one student against the task board and the contribution report | Tasks assigned, completed, on time, late (with days) and overdue match the board. Messages, document edits and files match the report's counts, with the daily message cap applied. |
| 13j.7 | `php artisan marking:preview <rubric> <group> --no-ai` with a key configured | "rule-based only". Nothing is sent to the provider. |
| 13j.8 | Configure a provider (see 13h) and run the command without `--no-ai` | "AI's view" lines with a short rationale citing the counts, and a summary with one request per student. A line "Flag: the baseline and the AI suggestion differ by more than one level" appears where they do. |
| 13j.9 | Set `AI_DAILY_LIMIT=1` and run again | The first student has an AI suggestion; the rest say "The daily limit of 1 AI suggestion requests has been reached" and show the rule-based level. |
| 13j.9a | Pick a group with very little recorded activity (a new group, or one with a handful of messages and no tasks) and run `php artisan marking:preview <rubric> <group>` | For each evidence criterion whose group total is below its minimum (`min_group_activity` in `config/marking.php`: 4 tasks, 6 messages, 6 edits and uploads by default) the line reads "insufficient data: There is too little activity in this group to compare students." with no rule-based or AI level, and the AI is not asked about it. Criteria with enough activity are suggested as usual. |
| 13j.9b | Read the section total under each student | "incomplete" with the marks so far and how many criteria are "undecided until you choose a level". It never shows 0 for a gap. A total appears only when every criterion has a level. |
| 13j.9c | Raise the group's activity to exactly a minimum (for example add messages until six count), run again | That criterion is suggested normally. Set the minimum to 0 for a type and the guard is off for it. |
| 13j.9d | In the preview, find a student with one task on time and one overdue out of two assigned | Reliability reads "1 of 2 assigned tasks was completed on time; 1 is overdue now (50%)." The overdue task lowers nothing a second time. |
| 13j.9e | With a provider configured, read the AI lines | Each reason cites at least one figure for its own criterion (messages only for Communication, edits and uploads only for Collaboration, on-time/late/overdue for Reliability, which names overdue tasks). A criterion whose reason cites no figure shows the rule-based level instead. |
| 13j.10 | In `storage/logs` and the output, look for the key | It appears nowhere. |
| 13j.11 | Sign in as a student and open every page | No suggestion, baseline, evidence or facts anywhere. As another lecturer, the evidence route for this rubric is not found. |
## 13k. Marking: choices, approval and the evidence report

Run `php artisan migrate`. Sign in as the lecturer who owns a subject with a saved rubric (13i) and a group with tasks and chat activity.

| # | Do | Expect |
|---|---|---|
| 13k.1 | Look at the sidebar | **Marking** is there, and stays highlighted on every marking page. |
| 13k.1a | Open a group on the Marking page | A panel called **Suggested levels** says: "We suggest a level for each criterion from what every student did in the group. You make the final decision." It does not name any AI company, model or setup. Buttons: **Refresh suggestions**, **Use all suggestions** (hover: "Fills in the suggested level for every student who is not approved yet. It does not approve anything.") and **Download evidence report**. |
| 13k.2 | Press **Refresh suggestions** | The button disables and shows a spinner. A panel appears: "Working on it... 1 of 4: <name>" with a progress bar that fills, one short line "If you leave, the students already done stay done. Refresh again to finish." while it runs, and one line per student that goes Waiting, Working on it, then "N with the AI's view, M from the numbers only". At the end it says "Done" with **Show the results**. With no AI set up every student still finishes, with the suggestions from the numbers. |
| 13k.2a | While it runs, open another tab on the app | The app still answers (on Windows `php artisan serve` is single-threaded, so a page may pause for a few seconds while a student is being worked on, and no longer). Close the marking tab part-way, reopen the group: students already finished keep their suggestions. |
| 13k.2b | Refresh when a student has approved marks | That student's line says "Approved, left as it is." and their suggestions do not change. |
| 13k.2c | Force a rate limit (a free-tier key, or set `ai.drivers.<driver>.base_url` to a server that answers 429 with `Retry-After: 5`) | That student's line shows "The AI is busy. Trying <name> again in N seconds (try 2 of 3)." counting down in the page; the other students are not held up. After three tries the line turns red with a **Retry** button and says they keep the suggestions from the numbers; **Retry** asks again for that student alone. |
| 13k.2d | Break the AI setup (a wrong key) and refresh | Each affected student's line says plainly "The AI could not answer just now" and that they keep the suggestions from the numbers. No key, no provider name and no error code appears. |
| 13k.2e | Open the student's page | The failed student still shows the rule-based level and the facts, and the AI box says "Not available" with the reason. |
| 13k.3 | Open a student | An amber banner: "Incomplete: 0 of 12 criteria decided." then "Still needed:" and what is missing, and "There is no total, and it cannot be approved...". It shows no marks figure. The Total tile agrees: a muted "Not yet" with a small **Incomplete** badge and "0 of 12 decided". **Approve marks** is disabled. Each section shows "Incomplete" with its marks so far. |
| 13k.4 | Open a student at full width (1366px) | The top shows **Total**, then one tile per section, as equal-width tiles in one row with equal heights. **Total** is a pale blue tile with dark text and a thin blue border (never black); each section is a white box with a thin grey border, rounded corners and a very light shadow; labels, figures and captions sit on the same lines. A figure that is not ready reads a small muted "Not yet" with an **Incomplete** badge, never a bold dash. Below: **Accept suggestions** and **Approve marks**, and on the right "Student 2 of 4" with **Previous student**, **Next student** and **All students**. |
| 13k.4b | Look at one System-suggested criterion (wide screen) | A light row, no box. Left: the name, "System-suggested · weight 2", one plain sentence and the **Why?** link. Right: the level chips in one row, each about 130px wide (never stretched), lined up with the right edge of the row. The rule-based level is already selected: dark with white text, and a small **Suggested** label inside the chip under its marks (it never sits on the border). Hover the selected chip: it stays dark with white text and lightens only slightly. Hover an unselected chip: the background greys but the text stays dark. Pressing a chip shrinks it a little; nothing around it moves. Tab to a chip: a blue outline shows. |
| 13k.4c | Click **Why?** on a suggested row, then **Level guide** on a Manual row | **Why?** opens **Records** (for example "Tasks: 4 given, 3 done"), **From the numbers** (a plain sentence with a small muted "Level 3 of 4" under it), **AI's view**, and what the selected level means. A row with no suggestion yet has no **Why?**. A Manual row has **Level guide** instead, listing each level that has a descriptor (levels without one are left out); if no level has a descriptor there is no link at all. | Once opened, the panel runs the full width of the row, under both columns.
| 13k.4d | Find a row where the AI picked a different level | A small "AI's view: Good" note under the chips. Click it: the Good chip becomes selected, and a reason box appears if that is neither suggestion. If the two are more than one level apart an amber "More than one level apart. Worth a look." shows too. Where they agree, nothing extra shows. |
| 13k.4e | Look at a Manual criterion, and one marked "Not enough group activity" | Same five chips, nothing selected, the calm line "You choose this one." No red or amber, no error styling. The insufficient one reads "There is too little activity in this group to compare students. You choose this one." |
| 13k.4f | Press **Accept suggestions** | A message says how many levels were accepted and that they are not final. Each System-suggested criterion with a suggestion now shows "Saved: ...". Manual and insufficient ones are untouched. A level you chose earlier is not changed. The sheet is not approved. The button is disabled while you have unsaved changes; when nothing is left it stays disabled with the short hint "All suggestions accepted" (or "Nothing to accept"). Hover the button to read what it does; there is no long paragraph on the page. |
| 13k.4i | Read the words on one student's page | Plain, short words throughout: **From the numbers** and **AI's view** (never "Rule-based" or "AI-suggested"), **Records** (never "Facts"). The sentences read like "Sent 15 messages. The group's average is 10.", "Made 9 edits and uploads. The group's average is 4.25." and "3 of 5 tasks were done on time, 1 was late (10 days) and 1 is overdue now (60% on time)." (**overdue** appears whenever there is overdue work). "This places it at level..." is gone from the sentence; the level shows as a small "Level 3 of 4" line under **From the numbers**. Level descriptions are exactly as in your rubric file. |
| 13k.4j | Approve a student, then look at the CSV evidence report | The records column reads in the simple wording ("Tasks: 4 given, 3 done"). An approval made before this wording existed still reads as it was approved. |
| 13k.4g | **Next student** and **Previous student** | Move through the group in the group list's order; the first student has no Previous and the last has no Next. |
| 13k.4h | At 768px and at 390px wide | The row stacks: name, kind and weight, then the sentence and link, then the chips in a grid of 3 columns (2 columns at 390px), each chip at least 44px high. Nothing scrolls sideways. The sticky Save and Approve bar still fits. (The sidebar is not collapsible yet, so at 390px judge the page with it hidden.) |
| 13k.5 | Find a criterion the group had too little activity for | The same five chips with nothing selected and "You choose this one." in plain grey text; the line reads "There is too little activity in this group to compare students. You choose this one." Nothing about it looks like an error. |
| 13k.6 | Choose a level that is neither suggestion | A reason box appears. Saving without a reason is refused with a note under that criterion, and nothing at all is saved. |
| 13k.7 | Choose a level for every System-suggested criterion and save, leaving the Manual ones | In a section totalled by **add** (or not mixed) the Manual criteria still count, so it stays incomplete and Approve stays disabled. In a section totalled by **scale** the Manual criteria do not count: the section completes, each Manual criterion says "Not counted in this section's total, so it needs no level", and the section total appears. |
| 13k.7a | In a mixed section open **How is this section totalled?** | The select is as wide as the card and has two short choices: "Add up every criterion" and "Scale the System-suggested criteria". One short line under it explains the choice you made ("Every criterion counts. All the marks are added up." or "Only System-suggested criteria count. Manual ones are left out."), and it changes as you change the select. If Add was picked for you, one more line says why. With Scale, a bold line shows the working: "System-suggested marks: 6 out of 8. Scaled to 10: 7.5." |
| 13k.7b | In a scaled section, choose only some of the System-suggested criteria and save | The section shows "n of m System-suggested criteria chosen" and **no marks figure**, and the banner says a scaled section shows no figure until all its System-suggested criteria have a level. Once the last is chosen the scaled total appears. |
| 13k.8 | Choose every Manual criterion too (or none, in a scaled section), and save | "Every criterion has a level. Total: ... of 40" and Approve marks is enabled. In a section totalled by **scale**, the working sits on the section's own line: "7.5 of 10 · scaled up from 6 out of 8 marks. Manual criteria are not counted." |
| 13k.8a | Approve a sheet with a scaled section, then reopen it | The Manual row's marks cell reads **Not counted (scaled)** instead of a number, so the visible rows add up to the section total. |
| 13k.8b | In a mixed section marked on level marks alone, open the page before choosing a method | **Add** is already selected, with a note that it was chosen for you and is saved when you save. A mixed section that multiplies by weight has nothing selected and says to choose. |
| 13k.8c | Group page: look at the Total column | A finished student shows "24.67 of 40". An unfinished one reads "incomplete" with "x of 40 so far"; if a scaled section is unfinished it reads only "incomplete" (the Marking column still gives "n of m decided"). |
| 13k.8d | Choose only some of a scaled section's System-suggested criteria and save | The banner has no "Marks so far" figure, only a note that one will appear once they are all chosen. |
| 13k.9 | Change a level without saving | "You have unsaved changes. Save them before approving." and Approve is disabled until you save. |
| 13k.10 | Approve | "Approved: <total> by <you> on <date>". The page is read-only. Saving a change is refused until you revoke. |
| 13k.11 | Add more messages and tasks for that student, then Refresh suggestions | The approved student's marks and facts do not change, and they are skipped by the refresh. |
| 13k.12 | Open the rubric version | "This version is locked." Its mode and evidence controls are disabled and there is no save button. Revoke the approval and they are editable again. |
| 13k.13 | On an unlocked version, change a criterion's **Decided by** and save | Saved. Making it Manual clears its evidence type and its suggestions. Making it System-suggested in a section with no scoring formula is refused. |
| 13k.13a | Group page: **Use all suggestions** | A message says how many levels were accepted for how many students and that nothing was approved. Students who are already approved are skipped and unchanged. No one becomes approved. |
| 13k.14 | Group page, **Download evidence report** | A CSV (opens with accents intact in Excel): rubric name and version, when it was made, provider and model, then a row per student and criterion with facts, rule-based level, AI level and reason, chosen level, override reason, marks, section total, scaling and approval. Unapproved students are marked "Draft (not approved)". |
| 13k.15 | Sign in as another lecturer and open this group's marking URL; sign in as a student | Not found for the other lecturer, forbidden for the student. The student's own pages show no marks, suggestions or marking menu. |
## 13l. Timezone (Malaysia time)

| Step | Action | Expected |
| --- | --- | --- |
| 13l.1 | Approve a student's marks shortly after midnight Malaysia time | The approval line shows Malaysian clock time (for example "31 Oct 2026, 2:57am"), not eight hours earlier. |
| 13l.2 | Send a chat message at 23:55 and another at 00:05 | In the chat they sit under two different day headings, and "Today" switches at midnight Malaysia time. |
| 13l.3 | Make a task due today and look at it at 23:00 | It is not overdue. After midnight it is. |
| 13l.4 | On a database with older rows, compare an old message's time with when you sent it | If it is eight hours early, run `php artisan app:shift-stored-times --dry-run`, then without `--dry-run` (once only). See docs/TIMEZONE.md. |

## 13m. Announcements

Run `php artisan migrate` first.

| Step | Action | Expected |
| --- | --- | --- |
| 13m.1 | Lecturer: My subjects, row action **Announcements** | A page for that subject with a form (Title, Message with a live "n / 2000" counter) and, below, the announcements already posted, newest first. Another lecturer's subject id in the URL gives "not found"; a student or admin gets "forbidden". |
| 13m.2 | Post one with a title and two lines of message, then click **Post** twice quickly | One announcement only. A message says how many students were notified. The line break is kept in the list. |
| 13m.3 | Try an empty title, an empty message, a 151-character title, a 2001-character message | Each is refused with a short message under the field. |
| 13m.4 | Student in that subject: open the Dashboard | There is no separate Announcements card. Each subject card is a soft pastel (blue, green, peach, lilac, sky or butter, the same for every student for that subject), with a thin border in a matching tone, the subject code in a pill in the accent colour, and a 2px accent ring on the first card instead of a solid fill. A subject with announcements has a rounded white pill along the bottom of its card: a bell, the latest title on one line, the time and a chevron (and "n announcements" when there are several). The main part of the card still opens the workspace. Text is easy to read on every colour. |
| 13m.4a | With an announcement the student has not opened | The pill shows a small red dot and "New announcement" (or "n new announcements") above the title, and the title is bold. Nothing animates. After the pill is opened the dot and label are gone straight away, and stay gone after a reload. Another student who has not opened it still sees the dot. |
| 13m.4b | Open the page and watch the network tab (or the server log) | Loading the dashboard sends nothing about reads. Opening a pill sends one small request for the announcements then showing; "Show earlier" sends one more for the rest. Opening the same one again sends nothing. |
| 13m.5 | Click the strip (or Tab to it and press Enter or Space) | The card opens in place to show up to the latest three: title, message, lecturer and time. A long message is cut to three lines with **Read more** / **Show less**. The cards beside it do not move or stretch. Click the strip again to close it. Text is easy to read on both the blue and the grey card. |
| 13m.5a | Open and close a pill several times | The card grows and shrinks smoothly (about 0.3s open, 0.2s close) and the cards below it, and the rest of the page, move with it with no jump. The text fades a little behind the height and the chevron turns. The card next to it does not stretch or move. "Show earlier" and "Read more" fade in gently. Reload the page: nothing animates. When it is closed, Tab skips everything inside it. With reduce-motion turned on, everything switches instantly. |
| 13m.6 | Post four or five | Only the latest three show when the card is open, with **Show earlier (n)** for the rest. |
| 13m.7 | Student: Notifications | One new row "SECJ3104: <title>" with its own icon. Clicking it opens the dashboard with that subject card already open and the announcement scrolled into view (an older one opens "Show earlier" too). A deactivated student got nothing. |
| 13m.8 | Lecturer: **Edit** an announcement and save | The text changes on the student's dashboard and no new notification arrives. |
| 13m.9 | Lecturer: **Delete** an announcement and confirm | It disappears from both pages and its notification is gone from every student's list. Deleting the whole subject does the same. |
| 13m.9a | Lecturer: the Announcements page of the subject | Under each announcement: "Opened by X of Y". Y is the active students now in the subject's groups; X is how many of them opened it. A deactivated student, or one who was moved out of the subject, is in neither number. |
| 13m.10 | Type `<b>bold</b>` in a title | It is shown as typed, not as bold. |

## 13n. Tidy boxes: student dashboard, task board, account

| Step | Action | Expected |
| --- | --- | --- |
| 13n.1 | Student dashboard with two subjects, one with announcements and one without | Both cards are the same height at rest and have the same bottom pill: the real one, or a quiet "No announcements yet" that cannot be clicked. Open one pill: only that card grows, the other stays level with the top. |
| 13n.2 | Look at "My pending tasks" and "Recent chat" | Two panels of the same height (about 26rem) side by side, stacked on a narrow screen. Tasks has a small grey header (Task, Subject, Deadline, Status) and up to five rows whose columns line up exactly; the date and its badge sit on one line. An overdue row has a thin red bar on its left, a faint red tint and an "Overdue" badge with a clock; a row due within two days has an amber bar, tint and "Due in n days" badge; a far-off date has no badge. Rows lighten softly on hover. On a phone each row stacks. With fewer than five tasks the rest of the panel is its own mint box with a dashed border, a tick in a circle and "Nothing else pending. Nice work." (or "You have no pending tasks."), clearly apart from the list. **Open board** is in the footer. Chat scrolls inside its panel with **Open chat**. The three stat tiles are unchanged. |
| 13n.3 | Task board | Three columns of the same height, each with a thin coloured bar on top, its name and a count. A column with no tasks at all shows one dashed box saying "Nothing here yet", "Nothing in progress" or "Nothing done yet"; a column with tasks shows no dashed box under them. Put 15 or more tasks in a column: no card or row is squashed or has its text cut, the column keeps its height and scrolls inside itself, and the page does not stretch. Every task title is readable in every column. |
| 13n.4 | Task board cards | To Do and In Progress are full cards with their status select, edit and delete always visible. In Done each task is one white card whose closed row is the same height every time: a small green tick, the title (cut with an ellipsis if long), the assignee's initials, the completion date and a chevron; there is no "Done" badge on each row. Click a row (or Tab to it and press Enter or Space): it opens smoothly, with no divider line, to show the description, who it belongs to, the deadline, and the status select, edit and delete. Opening another row closes the first. If the open row would be cut off by the column edge it scrolls gently into view, and the list does not jump. A closed row's controls cannot be tabbed to. The most recently completed task is at the top. Dragging between columns still works, and a card that is someone else's still cannot be moved. **New task** is also at the top of the To Do column. |
| 13n.5 | My account | The title "My account" lines up with the cards. **Your details** and **Change password** are the same height with their buttons on the same line, each heading has a small soft icon, and each card has three fields. A student sees a read-only, greyed **Student ID** with a lock; a lecturer or admin sees **Role**. Under New password: "Use at least 8 characters." The "Need your account closed or suspended?" note is a quiet bar under both cards. |
## 13o. Smooth movement (one shared way)

| Step | Action | Expected |
| --- | --- | --- |
| 13o.1 | Student dashboard: open and close an announcements pill; also on a lecturer's marking page open **Why?** and **Level guide** | The panel grows (about 0.3s) and shrinks (about 0.2s), its text fades a little behind, the chevron turns, and everything below moves with it with no jump. Closed panels cannot be tabbed into. These all use the same shared piece. |
| 13o.2 | Marking page: change **How is this section totalled?**; choose a level that is neither suggestion; make a change (the "unsaved changes" line) | The one-line explanation, the reason box and the unsaved note appear softly instead of snapping. |
| 13o.3 | Rubric pages (upload review, saved rubric): switch a criterion between Manual and System-suggested; admin: switch a user's role to Student | The extra field or note fades in. |
| 13o.4 | Student: open a document's history panel, start a new document; lecturer: marking group page, press **Refresh suggestions** | The panels fade in. |
| 13o.5 | Task board: add a task, delete one (the dialog), start the new-task form; chat: send a message from another account; notifications: switch the filter | The form and dialog fade and ease in; a new card, message or notification fades in at its place. Nothing that was already on the page moves, and nothing animates when the page first loads. |
| 13o.6 | Turn on reduce-motion and repeat 13o.1 and 13o.5 | Everything switches instantly. |
| 13o.7 | Menus, popovers, modals, toasts and the confirm dialog | They already had their own fade and scale and are unchanged. |
## 13p. Group chat as a messenger

| Step | Action | Expected |
| --- | --- | --- |
| 13p.1 | Student: Group chat at a laptop height (try about 700, 800 and 1000px tall) | The page does not scroll and has no empty strip under the card: the card fills the screen under the title with an even gap of about 20px around it. Only the group list and the message list scroll; the chat header and the message bar stay put. The page background is the normal app grey (no coloured glows). The card has a soft shadow and the conversation sits on the same blue-and-white wash as the sign-in page, held perfectly still (no dots, no movement). Left: a search box and one row per group (avatar in the subject colour, name, code, last message, time); the open row has a soft blue tint and a thin bar. White bubbles from others stand out; my bubbles are a stronger blue than the backdrop. The message bar floats with a soft shadow. Other pages (dashboard, board, account) still scroll normally. Under 900px the page scrolls as before and never sideways. |
| 13p.2 | Type in the search box; use many groups | The list narrows by group name or subject code, "No group matches that." appears when none do, and a long list scrolls inside its pane. Tab reaches each row and Enter opens that group's chat. |
| 13p.3 | Right pane header | The group name, subject code, "n members" and a **Group workspace** link, with a soft line under it. |
| 13p.4 | Read a thread | Opens at the latest message. Bubbles are never wider than about 70% of the pane. My messages are on the right in a soft blue bubble with dark text, with no name and no avatar. The times, the sender names and the day labels each sit on a small pale pill so they read clearly on the blue. Other people are on the left in white bubbles with a thin border and light shadow; their coloured initials and coloured name appear once at the start of a run, and their words are dark. Messages from one person within five minutes join into a run with tight spacing and square corners where they meet, and one small time under the last bubble. "Today", "Yesterday" or the date appears between days. |
| 13p.5 | Send a few emoji on their own; send an image with a caption; send a PDF | The emoji are large with no bubble. The image has rounded corners (at most 320px wide), no dark border, its caption under it in the same bubble, and opens the existing lightbox. The PDF is a compact card with a file icon, the name, the size and a small download icon on the right (no word "Download"; hover it to see "Download <name>"). Clicking the card downloads the file. |
| 13p.6 | Have someone else send while you are scrolled up | Your place does not move; a **New messages** button appears and takes you to the bottom. At the bottom you stay at the bottom as new messages slide in. Nothing animates when the page first loads. |
| 13p.7 | Composer | One rounded bar with attach and emoji buttons inside it and **Send**. It grows with the text up to a limit. Enter sends, Shift+Enter makes a new line. Under it one short line: "PDF, Word, Excel, PowerPoint, PNG, JPG, ZIP · up to 10 MB · private to <group>." There is no unread count on a group because chat does not keep track of what each person has read. |
| 13p.8 | Narrow the window under about 900px | The left pane becomes the strip of group chips above the conversation, and the conversation uses the full width. |
| 13p.9 | Click another group in the left list | The page does not reload. The old conversation fades out quickly, then the new one (header, messages and message bar) fades in with a tiny rise, opened at its latest message. The open-row tint and left bar fade from the old row to the new one. The address and the tab title change to that group. If it takes longer than a moment, light shimmering placeholder bars show instead of a blank pane; on a fast switch they never appear. |
| 13p.10 | Press Back and Forward in the browser; reload the page on a group; open a group's address directly | Back and Forward switch conversations in place. Reload and a direct visit still open that group. A group that is not yours gives "not found" on a direct visit. |
| 13p.11 | Type half a message in one group, switch to another, type something else, then switch back | Each group's unsent text comes back when you return to it. Reload the page and the drafts are gone (they are never stored). |
| 13p.12 | Click several groups very quickly | Only the last group you clicked ends up open, and no messages from an earlier group ever show under the newer group's name. Polling follows the open group. |
| 13p.13 | Keyboard and screen reader; reduce-motion | Tab to a row and press Enter: the chat switches and focus stays on that row. "Opened <group>" is announced. With reduce-motion on, the switch is instant with no fade and no placeholder. With JavaScript off, the rows are ordinary links and each opens its chat as a full page. Under about 900px the chips above the conversation switch in place too. |
## 13q. Documents editor for reports

| Step | Action | Expected |
| --- | --- | --- |
| 13q.1 | Student: open a document on a desktop screen | The writing sits on a white A4-width page (2.5 cm margins) on a soft grey background. The note "Pasted text arrives unformatted, on purpose." and the word and character count are still under the page. The toolbar has two rows: formatting on top, an **Insert** row (Table, Image, Link, Page break, Table of contents) below. Only **Save** and **Save and close** remain. |
| 13q.2 | Mark a line **H1**, another **H2**, another **H3** | H1 is the largest, then H2, then H3. |
| 13q.3 | **Image**: choose a PNG under 2 MB | The picture appears with an empty caption line ("Add a caption (optional)") and a short notice. Type a caption. Click the picture: it gets an outline and **Small / Medium / Full** and **Remove** appear in the Insert row; each size changes its width. Save, reload: picture, size and caption are still there. |
| 13q.4 | **Image**: choose a PDF, then a JPG over 2 MB | Each is refused with a plain message ("Only PNG, JPG or WebP pictures can be inserted." / "That picture is too large. Pictures can be up to 2 MB.") and nothing is inserted. |
| 13q.5 | **Link**: select words, press Link, enter `https://example.com`; then try `javascript:alert(1)` | The first becomes a link that opens in a new tab. The second is refused with "Links must start with http:// or https://." |
| 13q.6 | **New page** (under the sheet) | See 13s. |
| 13q.7 | **Table of contents** | A grey block lists the H1, H2 and H3 headings in order (indented by level), without page numbers. Change a heading: the button now reads **Update contents**; press it and the list follows. |
| 13q.8 | Open the same document as another group member, then as the lecturer, then sign in as a student from another group and open the picture's address (/documents/images/<number>) | Members and the subject's lecturer see the picture. The other student gets "not found". |
| 13q.9 | Save twice without changing anything | The second save says "No changes to save." and the history gains no version. Old versions still open and restore. |
| 13q.10 | Delete the document | Its picture files are removed with it. |

## 13r. Moving between pages

| Step | Action | Expected |
| --- | --- | --- |
| 13r.1 | Student: click Dashboard, My workspace, Task board, Documents, Group chat, Notifications and My account in turn | No white flash and no reload. The sidebar stays exactly where it is (it does not blink or slide). Only the content dips a little as you click and eases in from slightly right and below (about a quarter of a second). A thin periwinkle bar runs along the top on a slow page. The highlighted item moves to the page you are on. |
| 13r.2 | Same sweep as a lecturer, on every sidebar item | The same. |
| 13r.3 | On each page after arriving by the sidebar (no refresh), use it | Dashboard: announcement strips open and close. Documents: the editor loads, toolbar, pictures, saving work. Group chat: opens at the latest message, switching groups works, polling brings new messages; leave and come back several times and nothing doubles up. Task board: cards, columns and the Done row work. Marking: Suggest, evidence picker and the rubric sections work. Notifications: the bell count updates. |
| 13r.4 | Press Back and Forward | The pages swap smoothly. A new page starts at the top; Back returns to where you were scrolled. |
| 13r.5 | Click a CSV or PDF download, and Log out | They behave as before (a file downloads; logging out loads the sign-in page in full). |
| 13r.6 | Let the session expire (or sign out in another tab), then click a sidebar item | You land on the sign-in page. A page you may not open still shows its 403 or 404 page. A flash message after saving still appears on the next page. |
| 13r.7 | Turn on reduce-motion in the system, click through the sidebar | Pages change instantly with no movement. |

## 13s. Paged editor and Download PDF

New dependency: `barryvdh/laravel-dompdf` (PDF). Run `composer install`. The page logic is also covered by `npm run test:js`.

| Step | Action | Expected |
| --- | --- | --- |
| 13s.1 | Student: open a document | One white A4 sheet is centred on the grey desk (2.5 cm margins, fixed height). Under it: a left arrow, "Page 1 of N", a right arrow, **New page** and **Delete page**. There is no PDF button in the editor; only Save and Save and close. |
| 13s.2 | Put the cursor in the middle of a paragraph and press **New page** | The text after the cursor moves to a new page after this one; the view slides left to it (about 0.3 s) and the cursor is at its start. Press **New page** at the end of a paragraph: an empty page. |
| 13s.3 | Type or paste a lot of text past the bottom of the sheet | The last lines move to the start of the next page (created if needed). If you were typing in them, the view slides along and you keep typing. The sheet never grows. A picture taller than the page is scaled to fit. |
| 13s.4 | Arrows, **Alt + Left / Right**, **PageDown** on the last line (**PageUp** on the first), and a two-finger sideways swipe on a trackpad | Each moves exactly one page (a swipe moves one page, not several). Dragging with the mouse only selects text. |
| 13s.5 | Make more than 5 pages | A row of small dots appears; click one to jump to that page. |
| 13s.6 | Press **Undo** (or Ctrl + Z) after a New page, after an overflow and after a Delete page | The text, the pages and the page you were on come back exactly; Redo (Ctrl + Y) reapplies. Nothing is duplicated or lost. |
| 13s.7 | **Delete page** on a page with text, then on an empty page, then on the only page | Asks first only when the page has something on it. The last remaining page cannot be deleted (the button is greyed out). Empty pages are never removed by themselves. |
| 13s.8 | **Table of contents** with headings on several pages, then **Update contents** | The list has the headings of every page in order, without page numbers. The block can sit on any page. |
| 13s.9 | Open an old document that has no page breaks | It opens on page 1 and flows onto more pages. Press Save without changing anything: "No changes to save." and no new version. |
| 13s.10 | Word and character counts | They count the whole document, not the page on show. |
| 13s.11 | Documents list: **Download PDF** next to **Open** | A quiet button with a download icon (a members-only action). Pressing it shows "Preparing…", then downloads "<title> - <group>.pdf". The PDF is A4 with 12 pt text, the title first, the page numbers centred at the bottom, and every page of the editor on its own PDF page (an empty page too). Pictures with captions, tables, lists, links and colours show; the table of contents is expanded; no student or group name is in the body. |
| 13s.12 | Open a PDF address of another group's document | Refused (forbidden). A very large document gives a short message, not an error page. |
## 13t. Lecturer dashboard

| Step | Action | Expected |
| --- | --- | --- |
| 13t.1 | Lecturer: open the dashboard | The same look as the student's: a pastel card for each subject (its own colour, equal height, subject code chip, name, "n groups", and students / tasks done % / overdue), then four tiles (Needs attention, Awaiting marking, Groups, Students), a row of pills (Groups, Marking, Monitor progress, Students), and two panels of the same height. With more than 2 subjects the cards wrap into a grid, never a sideways scroller. |
| 13t.2 | Click a subject card; open its announcements strip | The card opens that subject. The strip shows the latest title and time, opens and closes with the same smooth motion as the student's, lists announcements with "Opened by X of Y", and has Post announcement. A subject with none says "No announcements yet" with a Post one link. Opening one strip does not stretch the other cards. |
| 13t.3 | Left panel "Groups needing attention" | Every group, most overdue first then lowest progress; each row has name, subject chip, members n/n with Full/Needs members, a progress bar with %, "x of y tasks", the overdue pill, Report and Monitor. It scrolls inside the panel; footer button Manage groups. |
| 13t.4 | Right panel "Recent activity" | The latest activity in the lecturer's groups (who did what, in which group, how long ago); scrolls inside; footer Monitor progress. Empty states are friendly, with no dashed boxes. |
| 13t.5 | Sign in as a student and open the lecturer dashboard address | Refused (forbidden). |

## 13u. Formatting that follows the selection, and the open-book look

| Step | Action | Expected |
| --- | --- | --- |
| 13u.1 | Select several lines, then choose **Font**, **Size**, a colour or a highlight | It applies to exactly the selected text, and the selection stays highlighted afterwards (clicking a toolbar button never drops it). |
| 13u.2 | Click in different places, then select across a bold and a normal word | The toolbar shows what is there: B, I, U, S, superscript/subscript, list, heading, alignment and the colour ring follow the cursor. Font, Size, Spacing, Before and After show **Mixed** when the selection has several values. |
| 13u.3 | Select three lines and change **Spacing** (Single, Comfortable, One and a half, Double, **Custom…** with 1.3, then with 5) | All three lines change and no other. The control then reads the choice, "Custom (1.3)" for the custom one, and "Mixed" if you extend the selection over a different line. 5 is refused with a short message. |
| 13u.4 | **Before** and **After** (0, 6, 12, 18 pt), **+ Indent** and **− Indent** (a centimetre each, not below 0), **First line** | Each applies to every paragraph the selection touches. First line toggles a 1.25 cm first-line indent on and off. |
| 13u.5 | Type H2O and select the 2, press **Subscript**; type m2 and superscript the 2 | They sit below and above the line, and stay so after Save and reload. |
| 13u.6 | Shortcuts with the cursor in the sheet: Ctrl+B, I, U; Ctrl+Alt+1, 2, 3 (and 0); Ctrl+L, E, R, J; Ctrl+Shift+7 and 8; Ctrl+] and [ | Each does what it does in Word. (Ctrl+R is taken while you are typing in the sheet, so it does not reload the page; elsewhere the browser's own shortcuts are untouched.) |
| 13u.7 | Make a change that makes a page too tall (double spacing on a full page), then Undo, Redo | The last lines move to the next page as before; Undo puts them back with the page and the selection where they were. |
| 13u.8 | Document with 3 or more pages: look beside the sheet | The next page peeks out on the right (about 70 px, slightly smaller and dimmed) and the previous page on the left, each a still miniature of the real page. Beyond the neighbour, up to three thin page edges show there are more pages. Page 1 has nothing on its left. |
| 13u.9 | Click the peeking page on either side | The view slides to that page like turning a page (about a third of a second), and the peeks and edges update. The arrows, Alt+arrows and a sideways swipe still work. Nothing scrolls sideways and nothing jumps. |
| 13u.10 | Go to the last page | Nothing on the right but a faint blank sheet with a plus; pressing it adds a page at the end and slides to it. |
| 13u.11 | Try to click or Tab into a miniature | You cannot: it is not editable and not focusable. |
| 13u.12 | Download a PDF of a document using all of the above | Spacing, space before and after, indents, first-line indent, super- and subscripts, colours and highlights are all there; page breaks match the pages. |
| 13u.13 | Ribbon layout at about 1440 px and 1920 px, then about 1100 px | One ribbon: Undo and Redo (stacked) at the far left, then Font, Paragraph, Spacing, Styles and Insert, each with exactly two rows of controls and its name on one line along the bottom. The groups share the width evenly with thin dividers, with no empty area at the right. At 1920 px it is one band; at 1440 px or narrower, whole groups wrap onto a second band (never half a group). Nothing about the pages or the counts is in the ribbon. |
| 13u.14 | Page controls and counts | Under the sheet, centred beneath it (not under the peeking pages): previous arrow, "Page 2 of 5", next arrow, New page, and a quiet Delete page (grey, red only on hover, disabled on the last page). Clicking "Page 2 of 5" opens a list of pages to jump to. They stay put while the sheets slide, and on a long page they stay at the bottom of the editor area. The word and character count is on the right of the footer line, with "Pasted text arrives unformatted, on purpose." on the left. |
| 13u.15 | Write on a long document and scroll | The ribbon stays at the top of the screen while you type; the sheet never slides under it, and there is only one scrollbar. |
## 13v. My account layout

| Step | Action | Expected |
| --- | --- | --- |
| 13v.1 | Open My account as a student, a lecturer and an administrator, at about 1920, 1440 and 1100 px wide | The title "My account" and the email sit at the same left edge, with the same padding, as Notifications and the Task board. At 1280 px and wider: three equal cards of one height (profile, Your details, Change password) filling the width, with both buttons on the bottom line. From about 900 to 1279 px: two columns, with the profile card across the top. Narrower: one column. The closing note is a full-width strip under the cards. Nothing is empty at the sides, and the page change from the sidebar only fades. |
| 13v.2 | Profile card for a student and a lecturer, at 1920 px and 1440 px | A flat soft band across the top, a 96 px avatar centred over its lower edge with a thin white ring, the name and the role, status and ID chips centred in the space beneath, and a row of small tiles at the bottom: the student's Groups, Tasks completed and Member since; the lecturer's Subjects, Groups, Students and Member since. The card looks full, with no gap in the middle, at the height the two form cards give it. The numbers are the signed-in person's own and match their dashboard. |

## 13w. Deleting a document keeps the evidence

| Step | Action | Expected |
| --- | --- | --- |
| 13w.1 | Student: delete a document (from the list and from the editor) | The confirmation says: "Removes the document from your group. Your lecturer can still see it." Afterwards it is gone from the group's list and its address shows "not found". |
| 13w.2 | Lecturer: open the group's Monitor progress page | A "Deleted documents" section lists it with who deleted it, how many versions it had and when. Opening it shows a "Deleted by <name> on <date>" banner, the content and the version history, read only, with no delete or restore. With nothing deleted the section is not there. |
| 13w.3 | Lecturer: the contribution report and the evidence for that group | The scores and the document-edit counts are the same as before the delete. |
| 13w.4 | Save a document twice without changing it | The second save says no changes, makes no version, and the contribution does not grow. |
| 13w.5 | `php artisan documents:purge-deleted` with no options, then with `--days=0 --dry-run`, then (after a backup) `--days=0` | The first refuses and removes nothing. The dry run lists the deleted documents and removes nothing. The last removes them for good, with their pictures (see BACKUP.md). |

## 13x. Group picker and Documents cards

| Step | Action | Expected |
| --- | --- | --- |
| 13x.1 | Student in two groups: My workspace, Task board and Documents | Under the header, "Your groups" and a row of small cards (about 16 rem wide) in each subject's soft colour, the same colour on all three pages. Each shows the subject code, the group name (ellipsis when long, full name on hover) and a quiet line: "N members", "N open tasks", "N documents". The current card has the fuller fill, a ring in the darker shade and sits a little higher; the others are white with a gentle hover lift. The change eases over about a fifth of a second. |
| 13x.2 | Press Tab to a card, then the arrow keys, then Enter | The arrows move between cards (Home and End too), with a visible focus ring; Enter opens that group. |
| 13x.3 | A student in one group | One card, with no hover lift or pointer. With more than four groups the cards wrap onto another line, with no sideways page scroll. Group chat still has its own list on the left. |
| 13x.4 | Documents: the cards | A grid of cards (3 to 5 across), equal height. Each shows a small first page of the latest saved version (pictures too), the title in at most two lines, "Started by", the last editor's initials with "Edited 16 minutes ago", a version pill (v5), then Open and quiet icons for Download PDF and Delete. A document of several pages has page edges behind the preview and a "3 pages" pill. A blank document shows a faint page. Open is always visible (not hover-only). Clicking anywhere else on the card opens it. |
| 13x.5 | Search by title, and switch Last edited / Title A to Z | Cards hide or reorder at once; the entrance does not play again. They come in one after another on load, fading and rising a few pixels, never sideways. |
| 13x.6 | A group with no documents | A friendly centred "No documents yet" with a New document button, no dashed box. |
| 13x.7 | Documents toolbar: the search box (about 320 px, with a magnifier and a clear button once it has text), the sort select and, far right, a quiet count ("3 documents") | One row directly under the group cards, on the same left edge as the cards. Typing filters the cards by title (a moment after you stop), without a page reload; the cards that stay do not move or replay their entrance. Last edited and Title A to Z both work. A search with no match shows "No documents match “text”" with a Clear search button. |
| 13x.8 | The New document tile with 0, 1, 3 and 10 documents, at 1920 px and 1440 px | Cards are at least about 300 px wide and fill the row (a few cards do not stretch into huge ones). With 1, 3 and 10 documents the last item is a tile in the group's colour with a thin solid border, a plus in a circle, "New document" and "Start a blank page"; it opens the same new-document form as the top button. With 0 documents the friendly empty state shows instead. The tile is hidden while searching and is not counted in "n documents". The previews still fill the card width in A4 proportion. |

## 13y. My workspace

| Step | Action | Expected |
| --- | --- | --- |
| 13y.1 | Student: My workspace at 1920 px and 1440 px | Five tiles in a row, all the same height and built the same way (icon chip, small label, big number, small caption). The first (Members) wears the group's soft colour; the others are white. Task progress is a normal tile: "0%", "0 of 3 tasks", the overdue pill if any, and a thin bar along its bottom. Below, two columns: Group members and Open tasks on the left (titles inside their cards, with a count beside each, Open board in the footer), Recent activity on the right. The two columns end level, with no empty gap under either. |
| 13y.2 | Add many activity entries (or use a busy group) | The feed scrolls inside its own card and never makes the page taller than the left column. |
| 13y.3 | Switch to another group (another subject) | The Members tile takes that group's colour; the panels show that group's data. Cards fade in one after another, rising a few pixels, never sideways. |
| 13y.4 | A group with no open tasks | Open tasks shows "Nothing open. Nice work." with a tick, not a dashed box. |

## 13z. Lecturer pages as cards

| Step | Action | Expected |
| --- | --- | --- |
| 13z.1 | Lecturer: My subjects at 1920 px and 1440 px, with 0, 2 and many subjects | Two to three pastel cards across (each subject always the same colour), equal height: code chip, name, then Groups (a link), Students, Default size and Project deadline ("No deadline" when unset, with an Overdue or Due-soon flag), and along the bottom Edit, Groups, Auto-assign students, Rubric, Announcements and a quiet Delete (same confirmation as before). With none: a friendly sentence and Create a subject, no dashed box. |
| 13z.2 | Groups, with 0, 2 and many groups | Two small tiles (Add one group, Auto-assign students) side by side, the subject filter as pills in each subject's colour that swap the list without a reload (the content just fades), then group cards 2 to 4 across: name with Manual or Auto, subject, avatars with n/n and Full or Open, a progress bar with the percentage, "9 of 13 tasks", a red overdue pill, and Manage and a quiet Delete. |
| 13z.3 | Marking | Rubrics as subject cards (rubric name, Version, a Locked pill when marks are approved, Upload or manage). Groups to mark as lighter group cards: students, the approved bar "0 of 4 approved", a status (Not started, In progress, All approved, or Needs a rubric) and Mark. |
| 13z.4 | Monitor progress | The same group cards with Monitor and Report, the groups with the most overdue tasks (then the lowest progress) first, and the line "Groups that need attention come first." No Manage or Delete. |
| 13z.5 | Hover a card; reload the page | A card rises a couple of pixels on hover; on load the cards come in one after another, fading and rising a few pixels, never sideways. With reduced motion neither happens. |

## 13aa. Contribution reports: Select group

| Step | Action | Expected |
| --- | --- | --- |
| 13aa.1 | Lecturer: Contribution reports at 1920 px and 1440 px with 0, 3 and many (more than four) groups, some with flagged members | With none: a friendly sentence and a Go to Groups button. Otherwise cards 2 to 4 across, equal height, always in their subject's soft colour (never red). A group with flagged members has a small red pill ("1 flagged") in its top right; one without has none. Each card shows the name, subject, avatars with n/n, the points, and a thin bar split by each member's share (hover a segment: name and points; an empty grey bar at 0 points). Groups with flags come first, with the line "Groups with flagged members come first." With more than four groups they sit under their subject's heading. |
| 13aa.2 | Click anywhere on a card, press Tab to it, and press View report | Each opens that group's report. The focused card shows a ring. Cards rise a little on hover and fade in one after another on load, never sideways. |
| 13aa.3 | The line under the subtitle, and the strip under the cards | One quiet line such as "3 groups · 4 flagged members · 256 points in total" (the flagged part is left out when there are none). Below the cards a slim strip "How points are counted" that opens and closes smoothly; open, it lists the activity types and their points (from the configuration; a type worth nothing is not listed) and says each colour in a card's bar is one member. With no groups neither shows. |
| 13aa.4 | One tooltip everywhere (student and lecturer) | Hover, then Tab to, the icon-only buttons (download, delete, edit, close, log out), the editor toolbar buttons (the name with the shortcut as small key chips, e.g. "Bold" + Ctrl B), a pill such as Low or Full, and a disabled button: each shows the same light rounded tooltip, above the control (below if there is no room), never the black browser one, and it stays inside the window for the first and last segment of a contribution bar. On a bar segment it shows an initials chip, the full name, "18 points · 11% of the group" (no percentage for a group with no points) and a red Low pill for a flagged member; the other segments dim while one is looked at. It goes away on leaving, scrolling, Escape and Tab. Check it again after moving between pages with the sidebar and after a chat or board update. |
| 13aa.5 | The sidebar: slim rail, role chip, group shortcuts (student and lecturer) | **Toggle:** the round button on the sidebar's right edge collapses it to a 76px rail (logo mark only, equal rounded tiles, black tile for the current page) and back, about a quarter of a second, with the page following without a jump or sideways scroll; the curved edge stays. Reload, and move with the sidebar links: the state is remembered and nothing re-animates. With no choice made, a window narrower than 1200px starts slim; a choice always wins. **Rail:** hover or Tab to each tile, a tooltip appears to the right; the notification count is a small red badge on the bell; the avatar has the logout icon under it. **Role chip:** "Student Portal", "Lecturer Portal" (admin: "Administrator Portal") as a chip with a tiny icon, a letter under the logo mark in the rail. **Lecturer:** Marking, Contribution and Monitor progress open into the lecturer's groups (most overdue tasks first, six at most, then "All groups"); the chevron toggles, the label still goes to the list page; check with 0 (plain items), 3 and 8 groups. **Student:** with one group the items are plain; with two or more, My workspace, Task board, Documents and Group chat open into the groups, each linking to that page for that group. On a group page its row is the black pill and its item a soft tile; on the list page the item is the black pill. The section holding the current page opens by itself; open sections are remembered. **Rail with the keyboard:** Enter, Space or the Down arrow on an item that opens shows its card beside the rail; arrows move through it, Escape closes it and returns to the item; it also closes on a click outside or 150ms after the pointer leaves. Check the dashboards, the task board, the editor, the group picker, subject cards and document cards at both widths. |
| 13aa.6 | The sidebar toggle works every time (student and lecturer) | Right after signing in, click the round button on the sidebar's edge: it collapses to the rail and the chevron turns right. Click again: it widens. Then click a sidebar link (a page change without reload) and use the toggle again, twice; also reload and use it; also Tab to it and press Enter and Space. Each time the width animates, labels fade, `aria-expanded` follows, and the state is the same after a reload. Repeat as a student. |
| 13aa.7 | The card beside the slim rail (lecturer; student with two groups) | Collapsed, hover Marking, Contribution and Monitor progress (and a student's My workspace, Task board, Documents, Group chat): the card sits right of the icon with its top level with it, with the item name in bold at the top; only that one box shows (no tooltip as well); the pointer can travel from the icon into the card without it closing, and it closes about 150ms after leaving both. Items near the top and bottom of the window keep the card fully visible. A click on the icon keeps the card open (a second click closes it); Enter, Space or the Down arrow open it and move into it, arrows move between rows, Escape closes it and returns to the icon, a click outside closes it. Plain items still show their tooltip. |
| 13aa.8 | Students: search suggestions and subject colours (lecturer) | Type one letter in the search box: a list opens under it, as wide as the box, within about a fifth of a second, without slowing the typing. Names that start with the letter come first; the matching part is bold; each row shows the matric number, a group chip and the subject chip. A student in two groups is two rows. Type part of a group name or a subject code: a row labelled Group or Subject comes first, and choosing it filters the table. Arrow Down/Up move the highlight, Enter chooses it (with nothing highlighted Enter and the Search button run the normal search), Escape, Tab and a click outside close it. Type something that matches nobody: one quiet line "No students match '…'". The little x clears the text. Subject chips in the table and in the list are the same colours as the subject cards on the dashboard. |
| 13aa.9 | Group switcher on a group's monitor page (lecturer) | Open a group from Monitor progress (or from the sidebar sub-menu): there is no "Your groups" row; the page starts with the back link, the group name, the subject line, then the tiles. In the header, left of "Contribution report", is a pill with a dot in the subject's colour, the group name and a chevron (a long name is cut, the full name shows in the tooltip). Click it: a white card opens under it with a quick fade, listing every group under its subject (code chip + subject name), ordered by subject then group name, the current one with a check and a soft highlight; it never leaves the window. Arrow keys move through the groups, Enter opens one, Escape closes it and returns to the pill. Choosing a group changes the page with the calm transition and the sidebar highlight follows. With more than 6 groups a search box tops the card: type a group or subject name to filter, "No groups match" when nothing fits. The round arrows beside the pill (tooltips "Previous group", "Next group") step through the same order and are greyed out at the first and last group. Alt+Left / Alt+Right are deliberately not used (the browser's Back / Forward). A group of another lecturer's is still a 404. The Contribution report page has the same switcher. Student pages (Task board, My workspace, Documents) still show their group cards. |
| 13aa.10 | Sections: the administrator enrols (admin) | **Back up the database first, then `php artisan migrate`** (see docs/BACKUP.md): every subject gets a section called "Main" with its groups and grouped students enrolled in it. Sign in as the administrator, User accounts > New user, role Student: a block "Subjects and sections" appears (hidden for Lecturer and Administrator). Add two rows: BCS3323 with section 1A and SECJ3104 with section 1B (the subject dropdown reads "CODE - Name (Lecturer)", so two lecturers' same-code subjects are told apart; typing in the section box suggests the subject's existing sections, a new name creates it; a subject already used in another row is greyed out). Save: the list shows small chips "BCS3323 · 1A" and "SECJ3104 · 1B" under the matric number, and the new subject filter beside the search narrows the list to a subject. Edit the same student: the rows are there; change a section, remove one, add one, save. Try the same subject twice, a 21-character section, an empty section: each is refused with a plain message. Put the student in a group of a subject, then try to remove that subject or change its section: refused ("Remove them from that group before..."), and nothing else on the form is saved either. |
| 13aa.11 | Sections: groups, formation and filters (lecturer) | Enrol 2 students in 1A and 2 in 1B of the same subject. Auto-assign students for that subject: choose a section first (a row of section pills, with how many are enrolled); only that section's students without a group are listed, and Balance/Import work on it alone (1B students are never listed or placed; in an import, a 1B matric number shows "Not in this section"). The created groups show their section. On a group's page the Assign dropdown lists only its section; forcing a student of another section is refused ("is in section 1B, but ... is a section 1A group"), and so is a student who is not enrolled. Filters (Groups, Students, Monitor progress, Contribution, Marking): one block directly under the title, above any figures. Row 1 "Subject": All subjects and a chip per subject (a dropdown with more than six). Row 2 "Section" appears only when exactly one subject is chosen and it has two or more sections: All sections, Section 1A, Section 1B. With "All subjects" there is no Section row and no combined "SUBJECT · Section" chips; picking another subject drops the section; picking a section keeps the subject; a stale or foreign section in the address is ignored. Cards and the Monitor progress tiles follow both. On Groups, with such a subject chosen, the summary reads "Section 1A: 2 groups · Section 1B: 1 group", and every group card carries an outlined "Section 1A" chip with a people icon (hover: "Section 1A of BCS3323", never in the subject colour). The same filter is on Students (the section sits next to the subject chip and the search finds "1B"), Monitor progress, Contribution and Marking; the choice lives in the address only. Group headers (monitor, contribution, marking, documents) and the group switcher name the section. Class list (button on Groups with a subject chosen): enrolled students by section, "No group yet" on those still waiting; nothing can be edited there. |
| 13aa.12 | Sections: students and announcements | As an enrolled student with no group: the dashboard still shows the subject card with the section and "No group yet", and the subject's announcements. As a lecturer post an announcement with Section "Only 1B": 1B students are notified and see it, 1A students are not and do not; "All sections" reaches both. "Opened by X of Y" counts only that section. A student in a group sees their section on the group card; opening another section's group is a 404. In Marking, the section appears in the group and student headers, the evidence CSV has a "Class section" line right after "Subject", and nothing about the marks changes. |
| 13aa.13 | Subjects belong to the administrator | Sign in as the administrator: the sidebar has **Subjects** right under User accounts (collapsed rail: a book icon with a tooltip). New subject: code, name, a Lecturer dropdown (lecturer accounts only, name and email) and a Sections block; create SECJ3104 for a lecturer with sections 1A and 1B. The card shows the code chip, name, lecturer, "Section 1A" / "Section 1B" chips and counts; the lecturer gets a notification "You have been assigned SECJ3104 Application Development". Search by code, name or lecturer; filter by lecturer. Edit an older subject: rename its "Main" section to 1A (its students and groups follow), add 1B. Try removing a section that has students or groups: refused with a clear message and nothing else is saved. Give the same code to the same lecturer twice: "This lecturer already has a subject with this code." Change the lecturer of a subject that has groups: a box appears ("Groups, marks and announcements stay with the subject and move to the new lecturer") and must be ticked; afterwards the old lecturer no longer sees it and the new one does. Delete: refused while it has groups or enrolled students; an empty one is deleted after typing its code. Under User accounts the student form picks the section from a dropdown of the subject's sections (with a "Manage sections in Subjects" link); a subject with no sections says "No sections yet". As a lecturer: My subjects has no New subject button or tile and no Delete; Edit shows the code, name and sections as text ("Managed by the administrator.") and lets you change only the project brief, due date and default group size; with no subjects it says "No subjects assigned yet. The administrator assigns subjects to lecturers." The class list and Auto-assign work without creating anything. |
| 13aa.14 | Project files for a subject | **Back up, then `php artisan migrate`** (docs/BACKUP.md). As the lecturer open My subjects > Edit: under the details the files live inside each assessment block (and a "Whole subject" block). Upload a PDF titled "Question 1" (kind Question), then a Word file titled "Marking guide" (kind Marking guide (file)): choosing that kind shows "For students to read. Marks are calculated from the rubric set up in the system, not from this file." Each row shows the kind chip, title, original name, size and date, with a download link and a Delete (confirm step). Try a text file renamed to .pdf, a .exe, a file over 10 MB and an eleventh file: each is refused with a plain message. As an enrolled student with a group: the group page's closed "Project brief" card lists the files under the brief; the PDF opens in the browser, the Word file downloads. As an enrolled student with no group: the dashboard card shows a "Files (N)" chip that opens a read-only list. As a student of another subject (or not signed in), a copied download link gives 403 / the sign-in page, and the files page of the subject is not found. Delete a file as the lecturer: students can no longer open it. |
| 13aa.15 | Semester start date and "Week 5" | **Back up, then `php artisan migrate`.** As the lecturer open My subjects > Edit: under the project due date there is "Semester start date" with the helper "The first day of Week 1. ...". Enter the Monday of Week 1 and save. The subject card on My subjects and on the lecturer dashboard shows a small "Week N" chip beside the code (hover: "Week N of the semester, counted from 6 Oct 2026"); the student dashboard card shows it on the header line, next to the section chip, without making the card taller; the group page's "Project brief" header reads "Group project due 22 December 2026 · Week 12". The start day and the six days after it are Week 1, the next day is Week 2. Clear the date: every chip and the "· Week" part disappear. A start date in the future, or more than 20 weeks ago, shows no chip. Nothing else changes (deadlines, reminders, marks, contribution). |
| 13aa.16 | Several assessments per subject | **Back up, then `php artisan migrate`** (docs/BACKUP.md): every subject now has one assessment, "Project", holding its old rubrics and marks. As the lecturer open My subjects > Edit: the **Assessments** card lists it ("Project", due date, Active only when there are several). Give "Project" the dates 1 Dec to 14 Dec (a second assessment cannot be added until the first has dates: overlapping dates are refused). Add "Assignment 1" (Assignment) 6 Oct to 24 Nov, then "Project" would not fit if it overlapped; adjust so the windows do not touch. The subject settings card no longer has a project due date: dates are set per assessment. Marking rubric > the selector row shows each assessment; upload a different rubric file for Assignment 1 and for Project (versions count per assessment). Marking: choose the subject, then the Assignment 1 chip: its rubric and groups; mark and approve one student; switch to Project: nothing is marked there. Contribution, Monitor progress (group page) and the Marking page have the same Assessment row: Project's contribution starts from zero while only Assignment 1 weeks had activity; the contribution CSV and the marking evidence CSV carry an "Assessment" line (after "Class section" in the evidence report). Work done between two assessments, or after the last due date, counts toward neither. Try to delete an assessment that has marks or approvals: refused with the reason. Change the dates of one with approved marks: a warning shows and the approved marks keep their snapshot. With only one assessment, dates change nothing: everything counts as before. |
| 13aa.17 | Brief, files and reminders per assessment | **Back up, then `php artisan migrate`** (docs/BACKUP.md): the old subject brief and files now belong to the first assessment ("Project"). As the lecturer open Edit on a subject with Assignment 1 (dated, e.g. 1 Sep to 15 Oct) and the Project (16 Oct to 15 Dec): the card at the top is "Subject settings" (Semester start date and Default group size only; no brief and no due date). Each assessment is a block showing its dates, its Brief (long ones clamp with Show more) and its Files; Edit opens an inline form with Title, Type, Start date, Due date, Weight and Brief. Use "Upload file" inside a block (no Assessment select; kinds Question / Marking guide (file) / Other): upload a question to Assignment 1 and one to the Project, and a handbook under "Whole subject" ("+ Add a shared file" while empty). After each save the page returns to that block. The two columns end on the same line on a wide screen./ As a student with a group, open My workspace or Task board: the closed card reads "Project brief" (the active one) with its due date, week and countdown; open it: the Project's brief and files plus the handbook ("Whole subject"); the chips below switch to Assignment 1 ("Closed") or a later one ("Opens 5 Jan") and the card stays open. A student without a group: the dashboard card's "Files (N)" opens a page grouped Assignment 1 / Project / Whole subject. The deadline chip on the card reads "Project · Due 15 Dec" when there are several assessments (hover for the countdown) and "Due 15 Dec · 12 days left" with one. Reminders: `php artisan collabspace:deadline-reminders --dry-run` lists every assessment due within the next 7 days (undated and past ones are skipped); running it for real tells each student once per assessment ("Assignment 1 for SECJ3104 is due on 14 Nov"), and running it again tells nobody twice. A subject with only the default assessment is reminded as before ("the group project is due in N days"). Deleting an assessment keeps its files as whole-subject files. |

## 13bb. My subjects: the New subject tile and the two panels

| Step | Action | Expected |
| --- | --- | --- |
| 13bb.1 | Lecturer: My subjects at 1920 px and 1440 px with 0, 3 and 6 subjects | Cards as before (same size and design). The last item in the grid is a soft tile with a thin solid border, a plus in a circle, "New subject" and "Add a course you teach"; it goes where the top-right New subject button goes. At 1920 px four cards fit in a row and the rest wrap. With 0 subjects: a friendly sentence and the tile alone, centred, and no panels. The tile lifts a little on hover and can be reached with Tab. |
| 13bb.2 | Panels under the cards, with and without deadlines and announcements | Two panels of one height. Upcoming deadlines: only subjects that have one, soonest first (overdue at the top), each with the subject chip, name, date and "in 28 days", "Today" or a red "Overdue by 3 days"; none set: "No project deadlines set yet." with an Edit a subject button. Recent announcements: the newest five across your subjects, each with the subject chip, title, "x hours ago" and "Opened by X of Y"; none: "No announcements yet.". Post announcement goes straight to the subject's announcements (one subject) or asks which subject first. |

## 13cc. Monitor progress, and a single group

| Step | Action | Expected |
| --- | --- | --- |
| 13cc.1 | Monitor progress at 1920 px and 1440 px with 0, 1 and many groups | With none: a friendly sentence and a Go to Groups button, no panels. With one group the list goes straight to it. Otherwise four tiles (Groups, Need attention, Overdue tasks, Average progress with a thin bar), subject pills, the group cards (most overdue first), then two panels of one height: Latest activity (the newest ten across your groups, each row linking to its group) and Members to check (members flagged on the Contribution reports page, with a red "Below half the group average" pill and a link to that report; "No one flagged. Nice." when none). |
| 13cc.2 | Click a subject pill | The cards, the activity rows and the flagged rows of other subjects hide, the four tiles change to match, and the cards that stay do not move. Nothing reloads. |
| 13cc.3 | Open a group (Monitor), with many tasks, many messages and a flagged member | Under the header the group cards (when you have several), five equal tiles (Members in the group's colour, Task progress as a normal tile with a bar). The board is one card ("Read-only — students move their own cards."), three columns as tall as the tallest of them up to a limit, each scrolling inside. Then Member activity and Documents (with Deleted documents closed until you open them) on the left, and on the right one card with Recent messages and Recent activity tabs, as tall as the left column and scrolling inside, fading quickly between tabs. The whole page is about a screen and a half at 1920 px. Nothing can be dragged or edited. |

## 13dd. The group project due date

| Step | Action | Expected |
| --- | --- | --- |
| 13dd.1 | New subject and Edit subject | The field is "Group project due date" with the line "Students see a countdown on their dashboard and get a reminder 7 days before. It does not lock tasks or documents. Optional.". My subjects shows it as "Group project due"; a student's dashboard and workspace show "Group project due 12 June 2026" with the countdown. |
| 13dd.2 | On a task board, add or edit a task and pick a date after the subject's group project due date | A small amber line appears under the date, "This is after the group project due date, 12 Jun 2026.", and goes away for an earlier date. Saving works either way. |

## 13ee. Documents under Monitor progress

| Step | Action | Expected |
| --- | --- | --- |
| 13ee.1 | Lecturer: Monitor progress, open a group, press "Open the full document list", read a document, open a past version, then a deleted document | The sidebar stays on **Monitor progress** the whole time. The address starts /lecturer/progress/<group>/documents. Above the title a breadcrumb: Monitor progress › Group › Documents (› the document's title on the viewer), every part a link except the last; the top-right pill is "Back to monitoring" on the list and "All documents" on the viewer. |
| 13ee.2 | The list | A read-only strip, the search and sort toolbar on the left, then document cards (first-page preview, page edges and a "3 pages" pill, title, Started by, last editor and time, version pill) with only a Read button. Deleted documents sit closed underneath, with "Deleted by X on date". An empty group is friendly, not dashed. |
| 13ee.3 | The viewer | The text on a white A4 sheet on the soft grey stage, not editable (no toolbar, no cursor). A document with several pages has Previous and Next, "Page 2 of 5" and the same slide, with the neighbours peeking. History is a card on the right with a row per version (each opens that version); an empty document shows a blank sheet with "This document is empty." |
| 13ee.4 | Open an old bookmark such as /lecturer/groups/<group>/documents or /lecturer/documents/<id> | It redirects to the new address. |
| 13ee.5 | Open the lecturer's document list with 1, 3 and 10 documents at 1920 px and 1440 px (and the student's Documents page) | The info strip, then the toolbar (search, sort, and "N documents" at the far right) in one row on the left edge, then a full-width grid of cards flowing left to right (3 to 5 across), each preview in A4 proportion. The cards are never squeezed into one narrow column. |

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

## Sidebar scrollbar and subject page layout

- Sidebar (expanded, collapsed rail, and the hover flyout): no scrollbar is visible, but the wheel/trackpad still scrolls the menu when the window is short. Logo, role chip and user card stay pinned. Nothing is clipped on the left edge at 1366 and 1920 px wide.
- Lecturer > My subjects > Edit: header shows the subject chip, name and section chips, with Save changes and Cancel on the right. Under it one summary card: code, name, lecturer, section chips, "Managed by the administrator.", links Groups / Class list / Marking / Contribution (each filtered to this subject), four tiles (Groups, Enrolled students, Without a group in the warning colour when above 0, Assessments) and, with two or more sections, a line like "Section 1A: 8 students, 2 groups · Section 1B: ...". No right column and no "Manage its groups" button. Then a compact "Subject settings" card and the Assessments card, both full width; at 1280 px and wider the assessment blocks sit two per row at equal height (an odd last one spans the row), a block widens to the full row while its Edit or Upload form is open, and below 1280 px they stack. Whole subject and Add assessment span the full width.
- Admin > Subjects > New / Edit: Details (code, name, lecturer) left, Sections right; header Save works; changing the lecturer of a used subject still asks for confirmation.

## Sidebar sub-menus follow the page (single-open accordion)

- As a student with two or more groups: open My workspace (chevron), then open Task board: My workspace closes, only Task board stays open. Click a group under Task board: the page changes and only Task board is open, that group lit. Go to Documents (its page or a group): only Documents is open. Go to Dashboard, Notifications or My account: every sub-menu is closed.
- Clicking the open item's chevron closes it, leaving none open. The nav list does not jump to the top when the page changes.
- As a lecturer: Marking, Contribution and Monitor progress behave the same way (opening one closes the other; going to Contribution opens only Contribution; Dashboard, Groups and Students close all).
- Slim rail and the collapse toggle behave as before (hover cards, click to open a card).

## Group submissions (PDF of the report)

Back up, then `php artisan migrate` (new tables `submissions` and `submission_documents`).

- As a student with a group (subject with an assessment due in the future and two documents): open Documents. Above the cards there is "Submit for assessment". Pick the assessment (the active one is chosen), tick both documents, reorder them with the arrows, add a note, press Submit: an inline step explains the PDF; "Yes, submit". The card shows "Submitted · On time", who, when, pages, Download PDF, Withdraw and no History yet. Open the PDF: a cover page (subject, assessment, group, section, members, submitted by, date and time, On time), then each document on a new page in your order, page numbers running on.
- Submit again with one document: the newest is current, History (1) shows the earlier one with a download. Another member sees the same card and got a notification ("<name> submitted <assessment> for <group>"); so did the lecturer.
- Withdraw (confirm): the card says Not submitted, the PDF stays in History. Change the assessment's due date to yesterday and reload: the Withdraw button is gone, and submitting still works but is marked Late.
- A group with no documents sees "Write a document first"; a subject with no assessment says there is nothing to submit for.
- As the lecturer: Monitor progress > the group, and Marking > the group, show a "Submission" card for the selected assessment (status chip, submitted by, date, pages, note, Open PDF in the browser, Download, History). With one subject chosen, the Marking and Monitor progress group cards show a small chip (Submitted, Late, Not submitted, Withdrawn) beside the subject name; in "All subjects" there is no chip. The evidence CSV has "Submitted" and "Submission" lines after "Assessment". A different lecturer, or a student outside the group, cannot open the PDF address.
- Delete a submitted document afterwards: the submission and its PDF are unaffected.
