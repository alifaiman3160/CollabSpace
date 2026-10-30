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

## 13d. Assignment brief and project deadline

| # | Check | Expected |
|---|---|---|
| 13d.1 | Lecturer → My subjects → edit SECJ3104 | Brief textarea and project deadline both filled from the seed |
| 13d.2 | Student dashboard | Brief panel under the group cards, with the subject code and days remaining |
| 13d.3 | Student workspace for Group A | Same brief, same countdown, no subject code (already in the heading) |
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
| 13j.4 | Save, open the version, change an evidence type and choose **Save evidence types** | "Evidence types saved." Only evidence changed: weights, levels and descriptors are as they were. The same amber note shows here for a mismatch. |
| 13j.5 | With `AI_DRIVER=none`: `php artisan marking:preview <rubric> <group>` | Per student: a facts line, then per System-suggested criterion the **Rule-based** level with its sentence and the marks it is worth, "AI-suggested: unavailable. AI suggestions are switched off", and "Needs lecturer input" for a criterion with no evidence type or no data. |
| 13j.6 | Check one student against the task board and the contribution report | Tasks assigned, completed, on time, late (with days) and overdue match the board. Messages, document edits and files match the report's counts, with the daily message cap applied. |
| 13j.7 | `php artisan marking:preview <rubric> <group> --no-ai` with a key configured | "rule-based only". Nothing is sent to the provider. |
| 13j.8 | Configure a provider (see 13h) and run the command without `--no-ai` | "AI-suggested" lines with a short rationale citing the counts, and a summary with one request per student. A line "Flag: the baseline and the AI suggestion differ by more than one level" appears where they do. |
| 13j.9 | Set `AI_DAILY_LIMIT=1` and run again | The first student has an AI suggestion; the rest say "The daily limit of 1 AI suggestion requests has been reached" and show the rule-based level. |
| 13j.9a | Pick a group with very little recorded activity (a new group, or one with a handful of messages and no tasks) and run `php artisan marking:preview <rubric> <group>` | For each evidence criterion whose group total is below its minimum (`min_group_activity` in `config/marking.php`: 4 tasks, 6 messages, 6 edits and uploads by default) the line reads "insufficient data: Not enough recorded activity in this group to compare students" with no rule-based or AI level, and the AI is not asked about it. Criteria with enough activity are suggested as usual. |
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
| 13k.2 | Marking: find the group, choose **Mark**, then **Refresh suggestions** | The button disables and shows a spinner. A panel appears: "Generating 1 of 4: <name>" with a progress bar that fills, and one line per student that goes Waiting, Working, then "N from the AI, M rule-based". At the end it says "Done" with **Show the results**. With no provider it says AI suggestions are off, and every student still finishes with rule-based suggestions. |
| 13k.2a | While it runs, open another tab on the app | The app still answers (on Windows `php artisan serve` is single-threaded, so a page may pause for a few seconds while a student is being worked on, and no longer). Close the marking tab part-way, reopen the group: students already finished keep their suggestions. |
| 13k.2b | Refresh when a student has approved marks | That student's line says "Approved, left as it is." and their suggestions do not change. |
| 13k.2c | Force a rate limit (a free-tier key, or set `ai.drivers.<driver>.base_url` to a server that answers 429 with `Retry-After: 5`) | That student's line shows "The AI provider asked us to slow down. Trying <name> again in N seconds (attempt 2 of 3)." counting down in the page; the other students are not held up behind a long request. After three tries the line turns red with a **Retry** button and says they keep their rule-based suggestions; **Retry** asks again for that student alone. |
| 13k.2d | Break the provider (a wrong key) and refresh | Each affected student's line says plainly that the provider did not respond and that the rule-based suggestion is kept. No key appears. |
| 13k.2e | Open the student's page | The failed student still shows the rule-based level and the facts, and the AI box says "Not available" with the reason. |
| 13k.3 | Open a student | A banner: "Incomplete: 0 of 12 criteria decided. Marks so far: 0", listing what is missing, and "There is no total, and it cannot be approved...". **Approve marks** is disabled. Each section shows "Incomplete" with its marks so far. |
| 13k.4 | Open a student at full width (1366px) | The top shows a dark **Total** tile ("x of 40", or a dash and "Incomplete"), a tile per section with the same, then **Accept suggestions** and **Approve marks**, and on the right "Student 2 of 4" with **Previous student**, **Next student** and **All students**. |
| 13k.4b | Look at one System-suggested criterion | One compact row: the name, one short plain sentence (for example "Finished 3 of 4 assigned tasks."), then five level chips, each with the level name and its marks in small text ("1.5 marks"). The rule-based level is already selected and carries a small **Suggested** tag. No big boxes. |
| 13k.4c | Click **Why?** on that row | It opens to show the facts, the full rule-based reason, the AI's reason, and what the selected level means. Click **Hide why** to fold it. |
| 13k.4d | Find a row where the AI picked a different level | A small "AI suggests Good" note under the chips. Click it: the Good chip becomes selected, and a reason box appears if that is neither suggestion. If the two are more than one level apart an amber "More than one level apart. Worth a look." shows too. Where they agree, nothing extra shows. |
| 13k.4e | Look at a Manual criterion, and one marked "Not enough group activity" | Same five chips, nothing selected, the calm line "You choose this one." No red or amber, no error styling. The reason for the insufficient one is under **Why?**. |
| 13k.4f | Press **Accept suggestions** | A message says how many levels were accepted and that they are not final. Each System-suggested criterion with a suggestion now shows "Saved: ...". Manual and insufficient ones are untouched. A level you chose earlier is not changed. The sheet is not approved. The button is disabled while you have unsaved changes, and when nothing is left to accept. |
| 13k.4g | **Next student** and **Previous student** | Move through the group in the group list's order; the first student has no Previous and the last has no Next. |
| 13k.4h | At 390px wide | Chips wrap onto two or three lines, the sentence sits under the criterion name, the totals tiles stack, nothing scrolls sideways, and the sticky Save and Approve bar still fits. |
| 13k.5 | Find a criterion the group had too little activity for | The same five chips with nothing selected and "You choose this one." in plain grey text; the reason ("Not enough recorded activity in this group to compare students") is under **Why?**. Nothing about it looks like an error. |
| 13k.6 | Choose a level that is neither suggestion | A reason box appears. Saving without a reason is refused with a note under that criterion, and nothing at all is saved. |
| 13k.7 | Choose a level for every System-suggested criterion and save, leaving the Manual ones | In a section totalled by **add** (or not mixed) the Manual criteria still count, so it stays incomplete and Approve stays disabled. In a section totalled by **scale** the Manual criteria do not count: the section completes, each Manual criterion says "Not counted in this section's total, so it needs no level", and the section total appears. |
| 13k.7a | In a mixed section open **How is this section totalled?** | The select is as wide as the card, so the option text is not cut off. Under it, two lines say what **Add** and **Scale** do. Choose **Scale**: a note says the manual criteria are not counted. Choose **Add**: they count and need a level. |
| 13k.7b | In a scaled section, choose only some of the System-suggested criteria and save | The section shows "n of m System-suggested criteria chosen" and **no marks figure**, and the banner says a scaled section shows no figure until all its System-suggested criteria have a level. Once the last is chosen the scaled total appears. |
| 13k.8 | Choose every Manual criterion too (or none, in a scaled section), and save | "Every criterion has a level. Total: ... of 40" and Approve marks is enabled. In a section totalled by **scale**, the working sits on the section's own line: "7.5 of 10 · scaled: 6 of 8 from the System-suggested criteria × 10 ÷ 8. Manual criteria are not counted." |
| 13k.8a | Approve a sheet with a scaled section, then reopen it | The Manual row's marks cell reads **Not counted (scaled)** instead of a number, so the visible rows add up to the section total. |
| 13k.8b | In a mixed section marked on level marks alone, open the page before choosing a method | **Add** is already selected, with a note that it was chosen for you and is saved when you save. A mixed section that multiplies by weight has nothing selected and says to choose. |
| 13k.8c | Group page: look at the Total column | A finished student shows "24.67 of 40". An unfinished one reads "incomplete" with "x of 40 so far"; if a scaled section is unfinished it reads only "incomplete" (the Marking column still gives "n of m decided"). |
| 13k.8d | Choose only some of a scaled section's System-suggested criteria and save | The banner has no "Marks so far" figure, only a note that one will appear once they are all chosen. |
| 13k.9 | Change a level without saving | "You have unsaved changes. Save them before approving." and Approve is disabled until you save. |
| 13k.10 | Approve | "Approved: <total> by <you> on <date>". The page is read-only. Saving a change is refused until you revoke. |
| 13k.11 | Add more messages and tasks for that student, then Refresh suggestions | The approved student's marks and facts do not change, and they are skipped by the refresh. |
| 13k.12 | Open the rubric version | "This version is locked." Its mode and evidence controls are disabled and there is no save button. Revoke the approval and they are editable again. |
| 13k.13 | On an unlocked version, change a criterion's **Decided by** and save | Saved. Making it Manual clears its evidence type and its suggestions. Making it System-suggested in a section with no scoring formula is refused. |
| 13k.13a | Group page: **Accept suggestions for all students** | A message says how many levels were accepted for how many students and that nothing was approved. Students who are already approved are skipped and unchanged. No one becomes approved. |
| 13k.14 | Group page, **Export evidence report** | A CSV (opens with accents intact in Excel): rubric name and version, when it was made, provider and model, then a row per student and criterion with facts, rule-based level, AI level and reason, chosen level, override reason, marks, section total, scaling and approval. Unapproved students are marked "Draft (not approved)". |
| 13k.15 | Sign in as another lecturer and open this group's marking URL; sign in as a student | Not found for the other lecturer, forbidden for the student. The student's own pages show no marks, suggestions or marking menu. |
## 13l. Timezone (Malaysia time)

| Step | Action | Expected |
| --- | --- | --- |
| 13l.1 | Approve a student's marks shortly after midnight Malaysia time | The approval line shows Malaysian clock time (for example "31 Oct 2026, 2:57am"), not eight hours earlier. |
| 13l.2 | Send a chat message at 23:55 and another at 00:05 | In the chat they sit under two different day headings, and "Today" switches at midnight Malaysia time. |
| 13l.3 | Make a task due today and look at it at 23:00 | It is not overdue. After midnight it is. |
| 13l.4 | On a database with older rows, compare an old message's time with when you sent it | If it is eight hours early, run `php artisan app:shift-stored-times --dry-run`, then without `--dry-run` (once only). See docs/TIMEZONE.md. |

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
