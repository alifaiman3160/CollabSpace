<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\ActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Demo data for CollabSpace.
 *
 * Every activity_logs row is written by the same helper that creates the
 * record it describes, so the log can never drift from the underlying data.
 * Weights are read from config/contribution.php and denormalised at write
 * time, matching how the application records them.
 *
 * Group A is hand-authored: Farah Aqilah is a deliberate free rider so
 * low-participation detection has a real case to surface.
 *
 * Every account uses the password "password".
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Anchor so relative timestamps stay coherent across a run. */
    protected CarbonImmutable $now;

    public function run(): void
    {
        $this->now = CarbonImmutable::now();

        $admin = $this->createUser('Rosli bin Kamarudin', 'admin@collabspace.test', 'admin');

        $siti = $this->createUser('Dr. Siti Rahayu Abdullah', 'siti.rahayu@collabspace.test', 'lecturer');
        $tan = $this->createUser('Dr. Tan Chee Wei', 'chee.wei@collabspace.test', 'lecturer');

        $students = $this->createStudents();

        $appDev = Subject::create([
            'lecturer_id' => $siti->id,
            'code' => 'SECJ3104',
            'name' => 'Application Development',
            'group_size' => 4,
        ]);

        $hci = Subject::create([
            'lecturer_id' => $tan->id,
            'code' => 'SECV2113',
            'name' => 'Human Computer Interaction',
            'group_size' => 3,
        ]);

        $groupA = $this->createGroup($appDev, 'Group A - KampusEats', 4, [
            $students['alif'], $students['nurul'], $students['weijie'], $students['farah'],
        ], $this->now->subDays(52));

        $groupB = $this->createGroup($appDev, 'Group B - MediTrack', 3, [
            $students['danial'], $students['priya'], $students['kaiming'],
        ], $this->now->subDays(50));

        $groupC = $this->createGroup($hci, 'Group 1 - Campus Wayfinder', 3, [
            $students['alia'], $students['arvind'], $students['syafiq'],
        ], $this->now->subDays(44));

        $this->seedGroupA($groupA, $students);
        $this->seedGroupB($groupB, $students);
        $this->seedGroupC($groupC, $students);

        $this->seedNotifications($students, $siti, $groupA);

        $this->report();
    }

    /*
    |--------------------------------------------------------------------------
    | People
    |--------------------------------------------------------------------------
    */

    protected function createUser(
        string $name,
        string $email,
        string $role,
        ?string $matric = null,
        bool $mustChange = false,
    ): User {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'matric_no' => $matric,
            'must_change_password' => $mustChange,
            'is_active' => true,
            'email_verified_at' => $this->now->subDays(60),
        ]);
    }

    /** @return array<string, User> */
    protected function createStudents(): array
    {
        $roster = [
            'alif' => ['Alif Aiman Jaafar', 'alif.aiman', 'A21EC0011'],
            'nurul' => ['Nurul Izzah Hamzah', 'nurul.izzah', 'A21EC0024'],
            'weijie' => ['Lee Wei Jie', 'lee.weijie', 'A21EC0037'],
            'farah' => ['Farah Aqilah Roslan', 'farah.aqilah', 'A21EC0042'],
            'danial' => ['Muhammad Danial Zulkifli', 'muhd.danial', 'A21EC0055'],
            'priya' => ['Priya Raman', 'priya.raman', 'A21EC0063'],
            'kaiming' => ['Chong Kai Ming', 'chong.kaiming', 'A21EC0078'],
            'alia' => ['Nur Alia Zainal', 'nur.alia', 'A22EC0009'],
            'arvind' => ['Arvind Kumar Selvam', 'arvind.kumar', 'A22EC0018'],
            'syafiq' => ['Syafiq Idris', 'syafiq.idris', 'A22EC0031'],
        ];

        $students = [];

        foreach ($roster as $key => [$name, $handle, $matric]) {
            // Syafiq has never signed in, so his temporary password is still in
            // place. That gives EnsurePasswordChanged something to act on.
            $students[$key] = $this->createUser(
                $name,
                $handle.'@student.collabspace.test',
                'student',
                $matric,
                mustChange: $key === 'syafiq',
            );
        }

        return $students;
    }

    /** @param  array<int, User>  $members */
    protected function createGroup(Subject $subject, string $name, int $size, array $members, CarbonImmutable $joinedAt): Group
    {
        $group = Group::create([
            'subject_id' => $subject->id,
            'name' => $name,
            'group_size' => $size,
        ]);

        foreach ($members as $i => $member) {
            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $member->id,
                // The leader registers the group, the rest join over a few days.
                'joined_at' => $joinedAt->addHours($i * 19),
            ]);
        }

        return $group;
    }

    /*
    |--------------------------------------------------------------------------
    | Record + matching-log helpers
    |
    | Each helper writes the record and its activity_logs row together, so the
    | log is always a faithful account of what is in the other tables.
    |--------------------------------------------------------------------------
    */

    /**
     * Delegates to the same recorder the running application uses, so seeded
     * and live contribution rows are produced by one implementation.
     */
    protected function log(User $user, Group $group, string $type, ?int $referenceId, CarbonImmutable $at): void
    {
        app(ActivityRecorder::class)->record($user, $group, $type, $referenceId, $at);
    }

    /** Creates the task, logs its creation, and logs its completion when done. */
    protected function task(
        Group $group,
        User $creator,
        ?User $assignee,
        string $title,
        string $status,
        CarbonImmutable $createdAt,
        ?CarbonImmutable $deadline = null,
        ?CarbonImmutable $completedAt = null,
        ?string $description = null,
    ): Task {
        $task = Task::create([
            'group_id' => $group->id,
            'assigned_to' => $assignee?->id,
            'created_by' => $creator->id,
            'updated_by' => $status === Task::STATUS_TODO ? null : ($assignee?->id ?? $creator->id),
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'deadline' => $deadline?->toDateString(),
            'completed_at' => $completedAt,
        ]);

        $task->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $completedAt ?? $createdAt,
        ])->save();

        $this->log($creator, $group, 'task_created', $task->id, $createdAt);

        if ($status === Task::STATUS_DONE && $assignee !== null && $completedAt !== null) {
            $this->log($assignee, $group, 'task_completed', $task->id, $completedAt);
        }

        return $task;
    }

    /** Creates the document with its first version and logs the creation. */
    protected function document(Group $group, User $creator, string $title, string $content, CarbonImmutable $at): Document
    {
        $document = Document::create([
            'group_id' => $group->id,
            'created_by' => $creator->id,
            'last_edited_by' => $creator->id,
            'title' => $title,
            'content' => $content,
        ]);

        $document->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        $version = new DocumentVersion([
            'document_id' => $document->id,
            'edited_by' => $creator->id,
            'version_no' => 1,
            'content' => $content,
        ]);
        $version->created_at = $at;
        $version->save();

        $this->log($creator, $group, 'document_created', $document->id, $at);

        return $document;
    }

    /** Appends the next version and logs the edit. */
    protected function revise(Document $document, User $editor, string $content, CarbonImmutable $at): void
    {
        $next = (int) $document->versions()->max('version_no') + 1;

        $version = new DocumentVersion([
            'document_id' => $document->id,
            'edited_by' => $editor->id,
            'version_no' => $next,
            'content' => $content,
        ]);
        $version->created_at = $at;
        $version->save();

        $document->forceFill([
            'content' => $content,
            'last_edited_by' => $editor->id,
            'updated_at' => $at,
        ])->save();

        $this->log($editor, $document->group, 'document_edited', $document->id, $at);
    }

    protected function message(Group $group, User $user, string $body, CarbonImmutable $at): void
    {
        $message = Message::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);

        $message->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        // Through the recorder's own method, so seeded messages are subject to
        // the same daily cap the running application applies.
        app(ActivityRecorder::class)->messageSent($message, $at);
    }

    /**
     * A chat message carrying a file. One with a caption writes both rows —
     * the upload and the message are two separate contributions — matching
     * what App\Livewire\GroupChat does for live messages.
     */
    protected function attachment(Group $group, User $user, ?string $body, string $fileName, int $bytes, CarbonImmutable $at): void
    {
        $message = Message::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'body' => $body,
            'file_path' => 'group-files/'.$group->id.'/'.$at->format('Ymd_His').'_'.$fileName,
            'file_name' => $fileName,
            'file_size' => $bytes,
        ]);

        $message->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        $recorder = app(ActivityRecorder::class);

        $recorder->fileUploaded($message, $at);

        if (filled($body)) {
            $recorder->messageSent($message, $at);
        }
    }

    /** Days-ago shorthand, kept short because the seed data is full of them. */
    protected function ago(int $days, int $hour = 10, int $minute = 0): CarbonImmutable
    {
        return $this->now->subDays($days)->setTime($hour, $minute);
    }

    /** Days-ahead shorthand, for deadlines that have not bitten yet. */
    protected function ahead(int $days, int $hour = 17, int $minute = 0): CarbonImmutable
    {
        return $this->now->addDays($days)->setTime($hour, $minute);
    }

    /*
    |--------------------------------------------------------------------------
    | Group A - Application Development, the free-rider case
    |
    | Contribution totals this produces, at the configured weights:
    |
    | Contribution totals, as created + completed + doc + edits + msgs + files:
    |
    |   Alif    14 + 15 + 6 + 12 + 12 + 4 = 63
    |   Nurul    8 + 10 + 3 +  6 + 10 + 2 = 39
    |   Wei Jie  4 +  5 + 0 +  6 +  8 + 2 = 25
    |   Farah    0 +  5 + 0 +  0 +  5 + 2 = 12
    |
    | Average 34.75, so the 0.5 low_participation_ratio puts the threshold at
    | 17.4. Farah clears zero but sits well under it — she did some real work
    | early and then stopped, which is what free riding actually looks like.
    | Wei Jie is quiet but clears the bar.
    |--------------------------------------------------------------------------
    */

    /** @param  array<string, User>  $s */
    protected function seedGroupA(Group $group, array $s): void
    {
        [$alif, $nurul, $weijie, $farah] = [$s['alif'], $s['nurul'], $s['weijie'], $s['farah']];

        // --- Tasks: 7 done, 3 in progress, 3 to do, 2 of them overdue ---

        $this->task($group, $alif, $alif, 'Draft the project proposal', Task::STATUS_DONE,
            $this->ago(50), $this->ago(44), $this->ago(45, 21, 30),
            'One page: the problem, who it is for, and the stack we are committing to.');

        $this->task($group, $alif, $farah, 'Collect requirements from the three cafeterias', Task::STATUS_DONE,
            $this->ago(48), $this->ago(42), $this->ago(43, 19, 55),
            'Opening hours, how they take orders now, and whether they would use a screen.');

        $this->task($group, $alif, $nurul, 'Set up the Laravel project and GitHub repo', Task::STATUS_DONE,
            $this->ago(49), $this->ago(43), $this->ago(44, 16, 10));

        $this->task($group, $alif, $alif, 'Design the database ERD', Task::STATUS_DONE,
            $this->ago(45), $this->ago(38), $this->ago(39, 23, 5),
            'Vendors, menu items, orders, order lines. Watch the price-at-time-of-order problem.');

        $this->task($group, $nurul, $weijie, 'Student sign-in and role middleware', Task::STATUS_DONE,
            $this->ago(41), $this->ago(34), $this->ago(35, 14, 40));

        $this->task($group, $nurul, $nurul, 'Vendor menu management screens', Task::STATUS_DONE,
            $this->ago(37), $this->ago(30), $this->ago(31, 20, 15));

        $this->task($group, $alif, $alif, 'Cart and checkout flow', Task::STATUS_DONE,
            $this->ago(31), $this->ago(24), $this->ago(25, 18, 45));

        $this->task($group, $alif, $alif, 'Live order status for students', Task::STATUS_INPROGRESS,
            $this->ago(21), $this->ahead(5),
            description: 'Queued, preparing, ready for collection. Polling for now, not websockets.');

        $this->task($group, $nurul, $nurul, 'Payment gateway sandbox integration', Task::STATUS_INPROGRESS,
            $this->ago(18), $this->ahead(8));

        // Overdue and still only in progress - Farah has not moved this in weeks.
        $this->task($group, $alif, $farah, 'Order history and receipts screen', Task::STATUS_INPROGRESS,
            $this->ago(26), $this->ago(9),
            description: 'Past orders with what was bought and what it cost.');

        // Overdue and never started.
        $this->task($group, $nurul, $farah, 'Write the user acceptance test script', Task::STATUS_TODO,
            $this->ago(20), $this->ago(6));

        $this->task($group, $weijie, $weijie, 'Notify the student when an order is ready', Task::STATUS_TODO,
            $this->ago(12), $this->ahead(12));

        $this->task($group, $weijie, null, 'Deploy to the faculty server', Task::STATUS_TODO,
            $this->ago(9), $this->ahead(16));

        // --- Documents ---

        $chapterOne = $this->document($group, $alif, 'Chapter 1 - Introduction',
            "1.1 Background\n\nThe three cafeterias on campus take every order at the counter. "
            ."Between 12:30 and 1:30 the queue at the main cafeteria regularly runs past twenty "
            ."people, and students with a 1pm class often give up and leave.\n\n"
            ."1.2 Problem Statement\n\nTo be written.",
            $this->ago(47, 9, 20));

        $this->revise($chapterOne, $nurul,
            "1.1 Background\n\nThe three cafeterias on campus take every order at the counter. "
            ."Between 12:30 and 1:30 the queue at the main cafeteria regularly runs past twenty "
            ."people, and students with a 1pm class often give up and leave.\n\n"
            ."1.2 Problem Statement\n\nThere is no way to order ahead. Vendors cannot see demand "
            ."before it arrives at the counter, and students cannot find out what is still "
            ."available without walking there.",
            $this->ago(44, 15, 5));

        $this->revise($chapterOne, $alif,
            "1.1 Background\n\nThe three cafeterias on campus take every order at the counter. "
            ."Between 12:30 and 1:30 the queue at the main cafeteria regularly runs past twenty "
            ."people, and students with a 1pm class often give up and leave.\n\n"
            ."1.2 Problem Statement\n\nThere is no way to order ahead. Vendors cannot see demand "
            ."before it arrives at the counter, and students cannot find out what is still "
            ."available without walking there.\n\n1.3 Objectives\n\n"
            ."i. Let a student browse each cafeteria's menu and place an order before arriving.\n"
            ."ii. Give vendors a screen showing incoming orders and what to prepare next.\n"
            ."iii. Tell the student when the order is ready for collection.",
            $this->ago(40, 11, 50));

        $this->revise($chapterOne, $weijie,
            "1.1 Background\n\nThe three cafeterias on campus take every order at the counter. "
            ."Between 12:30 and 1:30 the queue at the main cafeteria regularly runs past twenty "
            ."people, and students with a 1pm class often give up and leave.\n\n"
            ."1.2 Problem Statement\n\nThere is no way to order ahead. Vendors cannot see demand "
            ."before it arrives at the counter, and students cannot find out what is still "
            ."available without walking there.\n\n1.3 Objectives\n\n"
            ."i. Let a student browse each cafeteria's menu and place an order before arriving.\n"
            ."ii. Give vendors a screen showing incoming orders and what to prepare next.\n"
            ."iii. Tell the student when the order is ready for collection.\n\n"
            ."1.4 Scope\n\nThree campus cafeterias, two user roles, collection only — no delivery "
            ."and no cash handling in this iteration.",
            $this->ago(33, 17, 25));

        $this->revise($chapterOne, $alif,
            "1.1 Background\n\nThe three cafeterias on campus take every order at the counter. "
            ."Between 12:30 and 1:30 the queue at the main cafeteria regularly runs past twenty "
            ."people, and students with a 1pm class often give up and leave.\n\n"
            ."1.2 Problem Statement\n\nThere is no way to order ahead. Vendors cannot see demand "
            ."before it arrives at the counter, and students cannot find out what is still "
            ."available without walking there.\n\n1.3 Objectives\n\n"
            ."i. Let a student browse each cafeteria's menu and place an order before arriving.\n"
            ."ii. Give vendors a screen showing incoming orders and what to prepare next.\n"
            ."iii. Tell the student when the order is ready for collection.\n\n"
            ."1.4 Scope\n\nThree campus cafeterias, two user roles, collection only — no delivery "
            ."and no cash handling in this iteration.\n\n"
            ."1.5 Significance\n\nA shorter queue at the counter, and a vendor who knows at 11:45 "
            ."what the lunch hour is going to look like.",
            $this->ago(23, 14, 0));

        $requirements = $this->document($group, $alif, 'System Requirements Specification',
            "Functional Requirements\n\nFR1  The system shall authenticate users by email and password.\n"
            ."FR2  The system shall assign each user exactly one role: student or vendor.",
            $this->ago(42, 13, 40));

        $this->revise($requirements, $alif,
            "Functional Requirements\n\nFR1  The system shall authenticate users by email and password.\n"
            ."FR2  The system shall assign each user exactly one role: student or vendor.\n"
            ."FR3  A student shall browse the menu of each cafeteria, including item availability.\n"
            ."FR4  A student shall add items to a cart and place an order for collection.",
            $this->ago(36, 10, 15));

        $this->revise($requirements, $nurul,
            "Functional Requirements\n\nFR1  The system shall authenticate users by email and password.\n"
            ."FR2  The system shall assign each user exactly one role: student or vendor.\n"
            ."FR3  A student shall browse the menu of each cafeteria, including item availability.\n"
            ."FR4  A student shall add items to a cart and place an order for collection.\n"
            ."FR5  A vendor shall mark an item unavailable without deleting it from the menu.\n"
            ."FR6  A vendor shall move an order through queued, preparing and ready.",
            $this->ago(28, 16, 30));

        $this->revise($requirements, $weijie,
            "Functional Requirements\n\nFR1  The system shall authenticate users by email and password.\n"
            ."FR2  The system shall assign each user exactly one role: student or vendor.\n"
            ."FR3  A student shall browse the menu of each cafeteria, including item availability.\n"
            ."FR4  A student shall add items to a cart and place an order for collection.\n"
            ."FR5  A vendor shall mark an item unavailable without deleting it from the menu.\n"
            ."FR6  A vendor shall move an order through queued, preparing and ready.\n\n"
            ."Non-Functional Requirements\n\nNFR1  A menu page shall render in under two seconds on campus wifi.\n"
            ."NFR2  Passwords shall be stored using bcrypt.\n"
            ."NFR3  An order total shall be recorded at the price shown when it was placed.",
            $this->ago(19, 12, 45));

        $testPlan = $this->document($group, $nurul, 'Test Plan',
            "Scope\n\nUnit tests for the cart total, feature tests for sign-in and the order flow.",
            $this->ago(16, 15, 10));

        $this->revise($testPlan, $alif,
            "Scope\n\nUnit tests for the cart total, feature tests for sign-in and the order flow.\n\n"
            ."Test Cases\n\nTC01  Sign in with a valid student account.\n"
            ."TC02  An order cannot be placed with an empty cart.\n"
            ."TC03  An item marked unavailable cannot be added to a cart.\n"
            ."TC04  A student cannot open the vendor screens.\n"
            ."TC05  Marking an order ready notifies the student who placed it.",
            $this->ago(8, 9, 35));

        // --- Chat, in order ---

        $people = ['alif' => $alif, 'nurul' => $nurul, 'weijie' => $weijie, 'farah' => $farah];

        $this->conversation($group, $people, [
            ['alif', 50, 9, 5, 'Proposal draft is in the documents tab. Read it before Thursday and tell me if the scope is too big.'],
            ['nurul', 50, 12, 40, 'Read it. Scope looks right if we drop delivery, which I think we should.'],
            ['alif', 49, 10, 15, 'Agreed, collection only. Repo is up as well, everyone pull before you start anything.'],
            ['weijie', 49, 14, 30, 'Pulled.'],
            ['farah', 48, 21, 15, 'ok'],
            ['farah', 47, 20, 5, 'i can do the cafeteria interviews if nobody has started'],
            ['alif', 47, 21, 0, 'Yes please, take that one. Three cafeterias, ask how they take orders now.'],
            ['alif', 45, 11, 30, 'ERD is drafted. The tricky bit is storing the price on the order line, not just linking to the menu item.'],
            ['nurul', 45, 16, 20, 'Because the vendor can change the price later and the old receipt would change with it?'],
            ['alif', 45, 18, 10, 'Exactly that.'],
            ['farah', 43, 19, 50, 'Availability survey and interview notes.', 'cafeteria-interviews.xlsx', 31_224],
            ['farah', 43, 19, 52, 'all three would use it, main cafeteria is keenest'],
            ['nurul', 43, 21, 0, 'Nice, that is the requirements section basically written.'],
            ['alif', 39, 14, 20, 'ERD, final version.', 'kampuseats-erd-v3.png', 486_112],
            ['weijie', 38, 9, 45, 'Starting on sign-in and the role middleware.'],
            ['nurul', 37, 10, 30, 'I have the vendor menu screens. Where do design tokens live?'],
            ['alif', 37, 11, 15, 'tailwind.config.js. Please do not put raw hex anywhere.'],
            ['weijie', 35, 15, 0, 'Sign-in and role middleware are done, tests pass.'],
            ['alif', 34, 16, 40, 'Reviewed and merged.'],
            ['nurul', 31, 20, 30, 'Menu screens merged too. Vendors can mark an item unavailable without deleting it.'],
            ['weijie', 30, 12, 10, 'Added the non-functional requirements to the SRS.'],
            ['alif', 28, 9, 50, 'Farah, how is the order history screen going? It is the last one blocking the demo.'],
            ['nurul', 26, 14, 5, 'Anyone got the marking rubric? I cannot find it on the portal.'],
            ['nurul', 24, 16, 40, 'Marking rubric from the portal.', 'secj3104-rubric.pdf', 194_308],
            ['nurul', 24, 16, 43, 'Found it, never mind.'],
            ['alif', 25, 19, 0, 'Cart and checkout are done. You can place a real order end to end now.'],
            ['weijie', 23, 11, 20, 'Tried it on my phone at the cafeteria, works.'],
            ['alif', 23, 15, 0, 'Chapter 1 is at version 5, I think 1.5 is finished.'],
            ['alif', 22, 11, 5, 'Slides for the mid-semester demo.', 'midsem-demo.pptx', 2_845_776],
            ['nurul', 22, 13, 30, 'Slides look good. I will run the live bit.'],
            ['farah', 27, 23, 40, 'sorry busy with another subject, will catch up'],
            ['alif', 18, 10, 0, 'Farah, checking in again on the order history screen. It went overdue last week.'],
            ['weijie', 17, 13, 15, 'Test evidence screenshots.', 'test-evidence.zip', 1_204_992],
            ['nurul', 16, 15, 30, 'Payment sandbox needs a merchant account, I have applied for the test one.'],
            ['weijie', 14, 11, 0, 'Notifications for order-ready are next on my list.'],
            ['alif', 12, 9, 30, 'I will pick up the order history screen if I do not hear back by Monday.'],
            ['weijie', 10, 16, 0, 'Deploy needs a server account, I emailed the lab.'],
            ['farah', 7, 21, 55, 'ok noted'],
            ['weijie', 5, 10, 20, 'Lab replied, we get access Monday.'],
            ['alif', 3, 9, 15, 'Two weeks to submission. Let us close out the in-progress column this week.'],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Group B and Group C - healthy groups, for contrast
    |--------------------------------------------------------------------------
    */

    /** @param  array<string, User>  $s */
    protected function seedGroupB(Group $group, array $s): void
    {
        [$danial, $priya, $kaiming] = [$s['danial'], $s['priya'], $s['kaiming']];

        $this->task($group, $danial, $danial, 'Patient record schema', Task::STATUS_DONE,
            $this->ago(46), $this->ago(40), $this->ago(41, 15, 30));
        $this->task($group, $danial, $priya, 'Appointment booking flow', Task::STATUS_DONE,
            $this->ago(43), $this->ago(36), $this->ago(37, 19, 0));
        $this->task($group, $priya, $kaiming, 'Clinic dashboard charts', Task::STATUS_DONE,
            $this->ago(35), $this->ago(28), $this->ago(29, 12, 20));
        $this->task($group, $priya, $priya, 'Prescription PDF export', Task::STATUS_DONE,
            $this->ago(29), $this->ago(22), $this->ago(23, 17, 45));
        $this->task($group, $kaiming, $kaiming, 'SMS appointment reminders', Task::STATUS_INPROGRESS,
            $this->ago(20), $this->ahead(6));
        $this->task($group, $danial, $danial, 'Role-based access for clinic staff', Task::STATUS_INPROGRESS,
            $this->ago(17), $this->ahead(9));
        // Overdue.
        $this->task($group, $priya, $kaiming, 'Write Chapter 3 methodology', Task::STATUS_TODO,
            $this->ago(24), $this->ago(5));
        $this->task($group, $danial, $priya, 'Prepare demo dataset', Task::STATUS_TODO,
            $this->ago(10), $this->ahead(14));

        $proposal = $this->document($group, $danial, 'MediTrack Proposal',
            "Problem\n\nSmall clinics still run on paper appointment books.", $this->ago(45, 10, 30));
        $this->revise($proposal, $priya,
            "Problem\n\nSmall clinics still run on paper appointment books.\n\nProposed Solution\n\n"
            ."A web application for appointments, patient records and prescriptions.", $this->ago(38, 14, 15));
        $this->revise($proposal, $kaiming,
            "Problem\n\nSmall clinics still run on paper appointment books.\n\nProposed Solution\n\n"
            ."A web application for appointments, patient records and prescriptions.\n\nScope\n\n"
            ."One clinic, three staff roles, no billing module in this iteration.", $this->ago(26, 11, 40));

        $methodology = $this->document($group, $priya, 'Chapter 3 - Methodology',
            "3.1 Development Model\n\nIncremental, four two-week iterations.", $this->ago(23, 16, 5));
        $this->revise($methodology, $danial,
            "3.1 Development Model\n\nIncremental, four two-week iterations.\n\n3.2 Tools\n\n"
            ."Laravel 12, MySQL 8, Tailwind, GitHub Actions.", $this->ago(13, 13, 50));

        // --- Chat, in order ---

        $this->conversation($group, ['danial' => $danial, 'priya' => $priya, 'kaiming' => $kaiming], [
            ['danial', 45, 10, 0, 'Patient record schema is merged. Priya, booking can build on it now.'],
            ['priya', 44, 14, 20, 'On it. One question: can the same patient hold two appointments on the same day?'],
            ['danial', 44, 16, 5, 'Clinic said yes, different doctors. So no unique constraint on patient plus date.'],
            ['danial', 34, 15, 25, 'Clinic workflow diagram from the site visit.', 'meditrack-flow.png', 331_704],
            ['priya', 37, 11, 40, 'Booking flow is done. Please test the double-booking case, that is the one I am least sure of.'],
            ['danial', 36, 9, 30, 'Tested, it holds. Nice work.'],
            ['kaiming', 33, 17, 0, 'Dashboard charts are in.'],
            ['kaiming', 27, 12, 55, 'Dashboard screenshots.', 'dashboard-screens.zip', 962_140],
            ['priya', 26, 11, 15, 'Added the proposed solution section to the proposal.'],
            ['priya', 25, 15, 45, 'Kai Ming, the methodology chapter is yours right?'],
            ['kaiming', 25, 18, 20, 'Yes, on the methodology chapter this week.'],
            ['priya', 24, 9, 50, 'Great.'],
            ['priya', 21, 10, 10, 'Sample prescription export.', 'prescription-sample.pdf', 88_450],
            ['priya', 21, 10, 12, 'PDF export was fiddly but it works.'],
            ['danial', 13, 14, 0, 'Chapter 3 draft is up.'],
            ['kaiming', 12, 16, 30, 'SMS gateway needs a paid account. Looking at alternatives.'],
            ['kaiming', 10, 11, 45, 'Found a free sandbox, carrying on.'],
            ['danial', 6, 9, 20, 'Two weeks left, we are in decent shape. Demo dataset next.'],
        ]);
    }


    /** @param  array<string, User>  $s */
    protected function seedGroupC(Group $group, array $s): void
    {
        [$alia, $arvind, $syafiq] = [$s['alia'], $s['arvind'], $s['syafiq']];

        $this->task($group, $alia, $alia, 'Heuristic evaluation of the current campus app', Task::STATUS_DONE,
            $this->ago(40), $this->ago(34), $this->ago(35, 14, 0));
        $this->task($group, $alia, $arvind, 'Paper prototype for wayfinding', Task::STATUS_DONE,
            $this->ago(37), $this->ago(30), $this->ago(31, 16, 25));
        $this->task($group, $arvind, $arvind, 'Usability test with five students', Task::STATUS_DONE,
            $this->ago(28), $this->ago(21), $this->ago(22, 18, 40));
        $this->task($group, $alia, $alia, 'High-fidelity Figma screens', Task::STATUS_INPROGRESS,
            $this->ago(19), $this->ahead(7));
        $this->task($group, $arvind, $arvind, 'Analyse SUS scores', Task::STATUS_INPROGRESS,
            $this->ago(15), $this->ahead(10));
        // Overdue, and assigned to the student who has never signed in.
        $this->task($group, $alia, $syafiq, 'Accessibility audit against WCAG 2.1 AA', Task::STATUS_TODO,
            $this->ago(22), $this->ago(4));
        $this->task($group, $arvind, null, 'Record the walkthrough video', Task::STATUS_TODO,
            $this->ago(6), $this->ahead(15));

        $report = $this->document($group, $alia, 'Usability Evaluation Report',
            "Method\n\nNielsen's ten heuristics, two evaluators, severity rated 0 to 4.", $this->ago(36, 11, 15));
        $this->revise($report, $arvind,
            "Method\n\nNielsen's ten heuristics, two evaluators, severity rated 0 to 4.\n\nFindings\n\n"
            ."H1 Visibility of system status - severity 3. No progress feedback while the map loads.\n"
            ."H4 Consistency - severity 2. Back behaves differently on two screens.", $this->ago(29, 15, 45));
        $this->revise($report, $alia,
            "Method\n\nNielsen's ten heuristics, two evaluators, severity rated 0 to 4.\n\nFindings\n\n"
            ."H1 Visibility of system status - severity 3. No progress feedback while the map loads.\n"
            ."H4 Consistency - severity 2. Back behaves differently on two screens.\n"
            ."H6 Recognition over recall - severity 3. Building codes are shown without names.\n\n"
            ."Recommendations\n\nAdd a skeleton loader, unify the back affordance, label buildings.",
            $this->ago(17, 13, 20));

        $script = $this->document($group, $arvind, 'Usability Test Script',
            "Task 1  Find the nearest lecture hall to the library.\n"
            ."Task 2  Locate a specific lecturer's office.\n"
            ."Task 3  Report a broken lift.", $this->ago(31, 14, 30));
        $this->revise($script, $alia,
            "Task 1  Find the nearest lecture hall to the library.\n"
            ."Task 2  Locate a specific lecturer's office.\n"
            ."Task 3  Report a broken lift.\n\nPost-task\n\nSUS questionnaire, ten items, five-point scale.",
            $this->ago(25, 10, 50));

        // --- Chat, in order ---

        $this->conversation($group, ['alia' => $alia, 'arvind' => $arvind, 'syafiq' => $syafiq], [
            ['alia', 35, 14, 0, 'Heuristic evaluation is done. Five issues worth writing up, two of them severity 3.'],
            ['alia', 35, 14, 3, 'Heuristic evaluation spreadsheet.', 'heuristic-eval.xlsx', 47_820],
            ['arvind', 34, 13, 30, 'Read it. The building-code one is the worst — nobody knows what N28 means.'],
            ['arvind', 32, 11, 30, 'Paper prototype photos.', 'paper-prototype.zip', 3_118_664],
            ['alia', 32, 15, 10, 'Prototype looks great. The wayfinding arrows are much clearer than the old app.'],
            ['arvind', 28, 16, 0, 'Ran the usability test with five students. Notes are in the test script doc.'],
            ['arvind', 25, 10, 20, 'SUS mean is looking like 71 so far.'],
            ['alia', 24, 12, 40, 'That is above average, we can defend that in the report.'],
            ['alia', 17, 13, 20, 'I added the recommendations section to the evaluation report.'],
            ['alia', 15, 10, 0, 'Starting the hi-fi screens this week.'],
            ['alia', 9, 9, 30, 'Syafiq has not signed in yet. I have emailed him and copied Dr Tan.'],
            ['arvind', 4, 14, 15, 'I can take the accessibility audit if Syafiq stays unreachable.'],
        ]);
    }


    /**
     * Plays an ordered conversation into a group.
     *
     * Each entry is [speaker, days ago, hour, minute, text] or, for an
     * attachment, [speaker, days ago, hour, minute, text, filename, bytes].
     * Written as a script rather than a run of messages per person so replies
     * actually follow what they are replying to — these threads appear in
     * screenshots and in the UAT session, and a scrambled one reads as filler.
     *
     * @param  array<int, array>  $script
     */
    protected function conversation(Group $group, array $people, array $script): void
    {
        foreach ($script as $line) {
            [$who, $d, $h, $m, $text] = $line;
            $at = $this->ago($d, $h, $m);
            $user = $people[$who];

            if (isset($line[5])) {
                $this->attachment($group, $user, $text, $line[5], $line[6], $at);
            } else {
                $this->message($group, $user, $text, $at);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    /** @param  array<string, User>  $s */
    protected function seedNotifications(array $s, User $lecturer, Group $groupA): void
    {
        $rows = [
            [$s['farah'], 'task_overdue', 'Order history and receipts screen is overdue.', route('student.board.show', $groupA, false), true, $this->ago(9, 8, 0)],
            [$s['farah'], 'task_overdue', 'Write the user acceptance test script is overdue.', route('student.board.show', $groupA, false), false, $this->ago(6, 8, 0)],
            [$s['farah'], 'mention', 'Alif mentioned you in Group A - KampusEats.', route('student.chat.show', $groupA, false), false, $this->ago(5, 9, 15)],
            [$s['alif'], 'task_assigned', 'Nurul assigned you: Live order status for students.', route('student.board.show', $groupA, false), true, $this->ago(21, 10, 5)],
            [$s['alif'], 'low_participation', 'Farah Aqilah is below half the group average this week.', route('lecturer.progress.show', $groupA, false), false, $this->ago(3, 7, 30)],
            [$s['nurul'], 'document_edited', 'Wei Jie edited System Requirements Specification.', route('student.documents.group', $groupA, false), true, $this->ago(19, 12, 50)],
            [$s['weijie'], 'task_assigned', 'You were assigned: Notify the student when an order is ready.', route('student.board.show', $groupA, false), false, $this->ago(12, 11, 0)],
            [$s['syafiq'], 'task_assigned', 'You were assigned: Accessibility audit against WCAG 2.1 AA.', route('student.board.show', $groupA, false), false, $this->ago(22, 10, 20)],
            [$lecturer, 'low_participation', 'Group A - KampusEats has a member below the participation threshold.', route('lecturer.progress.show', $groupA, false), false, $this->ago(3, 7, 35)],
            [$lecturer, 'deadline', 'SECJ3104 final submission closes in two weeks.', route('lecturer.subjects', absolute: false), false, $this->ago(14, 9, 0)],
        ];

        foreach ($rows as [$user, $type, $message, $link, $isRead, $at]) {
            $notification = new AppNotification([
                'user_id' => $user->id,
                'type' => $type,
                'message' => $message,
                'link' => $link,
                'is_read' => $isRead,
            ]);
            $notification->created_at = $at;
            $notification->save();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Summary
    |--------------------------------------------------------------------------
    */

    protected function report(): void
    {
        $this->command?->info(sprintf(
            'Seeded %d users, %d subjects, %d groups, %d tasks (%d overdue), %d messages, %d documents / %d versions, %d activity logs.',
            User::count(),
            Subject::count(),
            Group::count(),
            Task::count(),
            Task::overdue()->count(),
            Message::count(),
            Document::count(),
            DocumentVersion::count(),
            ActivityLog::count(),
        ));

        $ratio = (float) config('contribution.low_participation_ratio');

        foreach (Group::with('members')->get() as $group) {
            $scores = $group->members
                ->mapWithKeys(fn (User $m) => [$m->name => $m->contributionScoreFor($group)]);

            $average = $scores->avg();
            $threshold = $average * $ratio;

            $this->command?->line("  {$group->name} - average {$average}, threshold ".round($threshold, 1));

            foreach ($scores as $name => $score) {
                $flag = $score < $threshold ? '  <-- low participation' : '';
                $this->command?->line("    {$name}: {$score}{$flag}");
            }
        }
    }
}
