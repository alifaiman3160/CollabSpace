<style>
    @page { size: A4 portrait; margin: 2.5cm; }

    body {
        font-family: 'DejaVu Sans', Arial, sans-serif;
        font-size: 12pt;
        line-height: 1.45;
        color: #111114;
        margin: 0;
    }

    /* The page number, centred in the footer: "1", "2", ... */
    .page-footer {
        position: fixed;
        bottom: -1.6cm;
        left: 0;
        right: 0;
        text-align: center;
        font-size: 10pt;
        color: #4d4d57;
    }

    .page-footer:after { content: counter(page); }

    .doc-title { font-size: 22pt; line-height: 1.2; font-weight: bold; margin: 0 0 16pt; }

    h1 { font-size: 20pt; line-height: 1.25; margin: 18pt 0 8pt; page-break-after: avoid; }
    h2 { font-size: 16pt; line-height: 1.25; margin: 16pt 0 6pt; page-break-after: avoid; }
    h3 { font-size: 13pt; line-height: 1.3; margin: 14pt 0 5pt; page-break-after: avoid; }

    p { margin: 0 0 9pt; }
    ul, ol { margin: 0 0 9pt; padding-left: 20pt; }
    li { margin: 2pt 0; }
    strong, b { font-weight: bold; }
    em, i { font-style: italic; }
    u { text-decoration: underline; }
    sup { vertical-align: super; font-size: 75%; }
    sub { vertical-align: sub; font-size: 75%; }
    a { color: #2f3fa8; text-decoration: underline; }
    blockquote { margin: 0 0 9pt; padding: 2pt 0 2pt 10pt; border-left: 2pt solid #c8c8d4; color: #4d4d57; }

    table { width: 100%; margin: 0 0 9pt; border-collapse: collapse; }
    th, td { border: 1pt solid #9a9aa8; padding: 4pt 6pt; text-align: left; vertical-align: top; }
    th { background-color: #eceef6; font-weight: bold; }

    figure { margin: 10pt 0; text-align: center; page-break-inside: avoid; }
    figure img { max-width: 100%; }
    figure[data-size="small"] img { width: 30%; }
    figure[data-size="medium"] img { width: 60%; }
    figure[data-size="full"] img { width: 100%; }
    figcaption { font-size: 10pt; color: #4d4d57; margin-top: 4pt; }

    /* "Page break" in the editor becomes a real break here. */
    .page-break { page-break-after: always; height: 0; margin: 0; padding: 0; }

    /* The contents, expanded from the headings. */
    .toc { margin: 0 0 14pt; }
    .toc-title { font-size: 16pt; font-weight: bold; margin: 0 0 8pt; }
    .toc-item { margin: 0 0 4pt; }
    .toc-l1 { font-weight: bold; }
    .toc-l2 { margin-left: 14pt; }
    .toc-l3 { margin-left: 28pt; font-size: 11pt; }
</style>
