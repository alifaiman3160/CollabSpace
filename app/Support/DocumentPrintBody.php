<?php

namespace App\Support;

use App\Models\Document;
use App\Models\DocumentImage;
use DOMXPath;
use Illuminate\Support\Facades\Storage;

/**
 * A document body made ready for the print layout (resources/views/documents/print.blade.php).
 *
 * The stored body is sanitised first, then two of its markers become what they stand for:
 *
 *   the dashed "Page break"      a real page break
 *   the table-of-contents block  a list built now from the document's H1, H2 and H3
 *
 * The contents list the headings in order, without page numbers. A page number is only right if the
 * renderer says where each heading landed, and a plain HTML-to-PDF step cannot be asked that, so it
 * is left out rather than risk a wrong one.
 *
 * Plain blocks only (no flex, no grid): the PDF step that uses this does not understand either.
 */
class DocumentPrintBody
{
    public function __construct(protected DocumentSanitizer $sanitizer) {}

    /**
     * @param  Document|null  $document  When given, the pictures of that document are embedded in the page
     *                                    (a PDF step cannot fetch them from the private disk by address).
     */
    public function render(?string $content, ?Document $document = null): string
    {
        $clean = $this->sanitizer->render($content);

        if (trim($clean) === '') {
            return '';
        }

        $headings = DocumentOutline::headings($clean);
        $dom = DocumentOutline::parse($clean);
        $xpath = new DOMXPath($dom);

        foreach (iterator_to_array($xpath->query('//p[@data-page-break]')) as $marker) {
            // A line of text inside it, so that two markers in a row still make an empty page.
            $break = $dom->createElement('div', "\u{00A0}");
            $break->setAttribute('class', 'page-break');
            $marker->parentNode->replaceChild($break, $marker);
        }

        foreach (iterator_to_array($xpath->query('//blockquote[@data-toc]')) as $block) {
            $block->parentNode->replaceChild($this->contents($dom, $headings), $block);
        }

        $this->embedPictures($dom, $xpath, $document);

        return DocumentOutline::inner($dom);
    }

    /**
     * Each picture becomes a data: address read from the private disk. A picture that is not one of this
     * document's own (or is gone) is dropped, with its caption, rather than left as a broken box.
     */
    protected function embedPictures(\DOMDocument $dom, DOMXPath $xpath, ?Document $document): void
    {
        foreach (iterator_to_array($xpath->query('//img')) as $img) {
            $figure = $img->parentNode;
            $id = (int) $img->getAttribute('data-image');
            $image = $document && $id > 0 ? DocumentImage::where('document_id', $document->getKey())->find($id) : null;
            $disk = Storage::disk(\App\Support\GroupFiles::DISK);

            if ($image === null || ! $disk->exists($image->path)) {
                $gone = $figure && $figure->nodeName === 'figure' ? $figure : $img;
                $gone->parentNode?->removeChild($gone);

                continue;
            }

            $img->setAttribute('src', 'data:'.$image->mime.';base64,'.base64_encode($disk->get($image->path)));
            $img->removeAttribute('data-image');
        }
    }

    /**
     * @param  list<array{level: int, text: string}>  $headings
     */
    protected function contents(\DOMDocument $dom, array $headings): \DOMElement
    {
        $toc = $dom->createElement('div');
        $toc->setAttribute('class', 'toc');

        $title = $dom->createElement('div', 'Table of contents');
        $title->setAttribute('class', 'toc-title');
        $toc->appendChild($title);

        if ($headings === []) {
            $none = $dom->createElement('div', 'No headings yet.');
            $none->setAttribute('class', 'toc-item');
            $toc->appendChild($none);

            return $toc;
        }

        foreach ($headings as $heading) {
            $line = $dom->createElement('div');
            $line->setAttribute('class', 'toc-item toc-l'.$heading['level']);
            $line->appendChild($dom->createTextNode($heading['text']));
            $toc->appendChild($line);
        }

        return $toc;
    }
}
