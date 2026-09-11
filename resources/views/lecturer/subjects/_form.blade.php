{{-- Shared fields for creating and editing a subject. Expects $subject or null. --}}
@php($subject = $subject ?? null)

<div class="flex max-w-xl flex-col gap-5 rounded-panel border border-surface-line bg-surface p-6 shadow-subtle">

    <div class="flex flex-col gap-2">
        <x-input-label for="code" value="Subject code" />
        <x-text-input id="code" name="code" type="text" required autofocus
                      :value="old('code', $subject?->code)"
                      placeholder="SECJ3104"
                      class="uppercase" />
        <x-input-error :messages="$errors->get('code')" />
    </div>

    <div class="flex flex-col gap-2">
        <x-input-label for="name" value="Subject name" />
        <x-text-input id="name" name="name" type="text" required
                      :value="old('name', $subject?->name)"
                      placeholder="Application Development" />
        <x-input-error :messages="$errors->get('name')" />
    </div>

    <div class="flex flex-col gap-2">
        <x-input-label for="group_size" value="Default group size" />
        <x-text-input id="group_size" name="group_size" type="number" min="2" max="10" required
                      :value="old('group_size', $subject?->group_size ?? 4)"
                      class="max-w-[140px]" />
        <p class="text-meta text-ink-400">
            Suggested when you create a group under this subject. Each group can still override it.
        </p>
        <x-input-error :messages="$errors->get('group_size')" />
    </div>
</div>
