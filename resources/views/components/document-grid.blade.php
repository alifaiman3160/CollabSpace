{{--
    The grid the document cards sit in: auto-fill, a card at least 18.75rem wide, so they flow left to right in as many
    columns as fit. One definition, used by the students' list, the lecturer's list and the lecturer's deleted section.
--}}
<section {{ $attributes->merge(['aria-label' => 'Documents'])->class('grid grid-cols-[repeat(auto-fill,minmax(18.75rem,1fr))] gap-[18px]') }} data-document-grid>
    {{ $slot }}
</section>