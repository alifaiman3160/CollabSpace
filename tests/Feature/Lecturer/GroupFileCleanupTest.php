<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Subject;
use App\Models\User;
use App\Support\GroupFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deleting a group cascades its rows, but a foreign key cannot reach the
 * filesystem. Chat uploads used to stay in storage/app/private for good:
 * unreachable, and still holding the group's files.
 *
 * These drive the upload through the real chat component, so what is asserted
 * gone is a file the application itself wrote, at the path it chose.
 */
class GroupFileCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected Group $group;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(GroupFiles::DISK);

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->subject = Subject::create([
            'lecturer_id' => $this->lecturer->id,
            'code' => 'SECJ3104',
            'name' => 'App Dev',
            'group_size' => 4,
        ]);

        $this->group = Group::create([
            'subject_id' => $this->subject->id,
            'name' => 'Group A',
            'group_size' => 4,
        ]);

        $this->student = User::factory()->student()->create(['must_change_password' => false]);

        GroupMember::create([
            'group_id' => $this->group->id,
            'user_id' => $this->student->id,
            'joined_at' => now(),
        ]);
    }

    /** Sends a real attachment through the chat component and returns its path. */
    protected function uploadTo(Group $group, string $name = 'report.pdf'): string
    {
        $member = $group->members()->first();

        Livewire::actingAs($member)
            ->test(\App\Livewire\GroupChat::class, ['group' => $group])
            ->set('attachment', UploadedFile::fake()->create($name, 60, 'application/pdf'))
            ->call('send')
            ->assertHasNoErrors();

        $path = Message::where('group_id', $group->id)->whereNotNull('file_path')->latest('id')->value('file_path');

        $this->assertNotNull($path);
        Storage::disk(GroupFiles::DISK)->assertExists($path);

        return $path;
    }

    public function test_deleting_a_group_deletes_its_uploaded_files(): void
    {
        $path = $this->uploadTo($this->group);

        $this->actingAs($this->lecturer)
            ->delete(route('lecturer.groups.destroy', $this->group))
            ->assertRedirect();

        Storage::disk(GroupFiles::DISK)->assertMissing($path);

        // The directory itself goes too, not just its contents.
        $this->assertDirectoryIsGone($this->group->id);

        $this->assertDatabaseMissing('groups', ['id' => $this->group->id]);
        $this->assertDatabaseMissing('messages', ['group_id' => $this->group->id]);
    }

    public function test_every_file_in_the_group_goes_not_just_the_last_one(): void
    {
        $first = $this->uploadTo($this->group, 'chapter-one.pdf');
        $second = $this->uploadTo($this->group, 'chapter-two.pdf');
        $third = $this->uploadTo($this->group, 'slides.pdf');

        $this->assertNotSame($first, $second);

        $this->actingAs($this->lecturer)->delete(route('lecturer.groups.destroy', $this->group));

        foreach ([$first, $second, $third] as $path) {
            Storage::disk(GroupFiles::DISK)->assertMissing($path);
        }
    }

    public function test_deleting_a_subject_deletes_the_files_of_every_group_under_it(): void
    {
        $second = Group::create([
            'subject_id' => $this->subject->id,
            'name' => 'Group B',
            'group_size' => 4,
        ]);

        $mate = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $second->id, 'user_id' => $mate->id, 'joined_at' => now()]);

        $fromA = $this->uploadTo($this->group);
        $fromB = $this->uploadTo($second);

        $this->actingAs($this->lecturer)
            ->delete(route('lecturer.subjects.destroy', $this->subject))
            ->assertRedirect(route('lecturer.subjects'));

        Storage::disk(GroupFiles::DISK)->assertMissing($fromA);
        Storage::disk(GroupFiles::DISK)->assertMissing($fromB);

        $this->assertDirectoryIsGone($this->group->id);
        $this->assertDirectoryIsGone($second->id);

        $this->assertDatabaseMissing('subjects', ['id' => $this->subject->id]);
        $this->assertDatabaseMissing('groups', ['subject_id' => $this->subject->id]);
    }

    public function test_another_groups_files_are_left_alone(): void
    {
        $other = Group::create([
            'subject_id' => $this->subject->id,
            'name' => 'Group B',
            'group_size' => 4,
        ]);

        $mate = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $other->id, 'user_id' => $mate->id, 'joined_at' => now()]);

        $doomed = $this->uploadTo($this->group);
        $keep = $this->uploadTo($other);

        $this->actingAs($this->lecturer)->delete(route('lecturer.groups.destroy', $this->group));

        Storage::disk(GroupFiles::DISK)->assertMissing($doomed);
        Storage::disk(GroupFiles::DISK)->assertExists($keep);
    }

    public function test_a_group_that_never_had_an_upload_deletes_cleanly(): void
    {
        $this->actingAs($this->lecturer)
            ->delete(route('lecturer.groups.destroy', $this->group))
            ->assertRedirect();

        $this->assertDatabaseMissing('groups', ['id' => $this->group->id]);
    }

    public function test_a_lecturer_who_does_not_own_the_group_deletes_nothing(): void
    {
        $path = $this->uploadTo($this->group);

        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($stranger)
            ->delete(route('lecturer.groups.destroy', $this->group))
            ->assertNotFound();

        Storage::disk(GroupFiles::DISK)->assertExists($path);
        $this->assertDatabaseHas('groups', ['id' => $this->group->id]);
    }

    protected function assertDirectoryIsGone(int $groupId): void
    {
        $this->assertNotContains(
            GroupFiles::directoryFor($groupId),
            Storage::disk(GroupFiles::DISK)->directories('group-files'),
        );
    }
}
