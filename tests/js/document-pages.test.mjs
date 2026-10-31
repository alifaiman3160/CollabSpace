import test from 'node:test'
import assert from 'node:assert/strict'

import {
    PAGE_BREAK, splitPages, joinPages, splitHtmlAtTextOffset, splitPageAtCaret,
    cascadeOverflow, isPageEmpty, removePage, textLength,
} from '../../resources/js/document-pages.js'

test('a body with no marker is one page', () => {
    assert.deepEqual(splitPages('<p>Hello</p>'), ['<p>Hello</p>'])
    assert.deepEqual(splitPages(''), [''])
    assert.deepEqual(splitPages(null), [''])
})

test('split and join are lossless, including empty pages and consecutive markers', () => {
    const cases = [
        ['<p>a</p>'],
        ['<p>a</p>', '<p>b</p>'],
        ['<p>a</p>', '', '<p>c</p>'],
        ['', ''],
        ['<p>a</p>', ''],
        ['', '<p>b</p>'],
        ['', '', ''],
    ]

    for (const pages of cases) {
        assert.deepEqual(splitPages(joinPages(pages)), pages)
        assert.equal(joinPages(splitPages(joinPages(pages))), joinPages(pages))
    }
})

test('the marker the sanitiser stores is recognised in each of its spellings', () => {
    for (const marker of ['<p data-page-break="1"><br></p>', '<p data-page-break="1"><br /></p>', '<p data-page-break="1"></p>']) {
        assert.deepEqual(splitPages(`<p>a</p>${marker}<p>b</p>`), ['<p>a</p>', '<p>b</p>'])
    }
})

test('splitting text at an offset keeps tags balanced on both sides', () => {
    assert.deepEqual(splitHtmlAtTextOffset('Hello world', 5), ['Hello', ' world'])
    assert.deepEqual(splitHtmlAtTextOffset('<b>ab</b>cd', 2), ['<b>ab</b>', 'cd'])
    assert.deepEqual(splitHtmlAtTextOffset('<b>abcd</b>', 2), ['<b>ab</b>', '<b>cd</b>'])
    assert.deepEqual(splitHtmlAtTextOffset('<b><i>abcd</i></b>', 3), ['<b><i>abc</i></b>', '<b><i>d</i></b>'])
    assert.deepEqual(splitHtmlAtTextOffset('a&amp;b', 2), ['a&amp;', 'b'])
    assert.deepEqual(splitHtmlAtTextOffset('a<br>bc', 2), ['a<br>b', 'c'])
    assert.equal(textLength('a&amp;b<b>c</b>'), 4)
})

test('New page in the middle of a paragraph moves the text after the cursor, and nothing is lost', () => {
    const blocks = ['<h1>Title</h1>', '<p>First half. Second half.</p>', '<p>Next</p>']
    const [stay, move] = splitPageAtCaret(blocks, 1, 'First half.'.length)

    assert.equal(stay, '<h1>Title</h1><p>First half.</p>')
    assert.equal(move, '<p> Second half.</p><p>Next</p>')
    assert.equal(textOnly(stay + move), textOnly(blocks.join('')))
})

test('New page at the start or end of a block does not cut it', () => {
    const blocks = ['<p>One</p>', '<p>Two</p>', '<p>Three</p>']

    assert.deepEqual(splitPageAtCaret(blocks, 1, 0), ['<p>One</p>', '<p>Two</p><p>Three</p>'])
    assert.deepEqual(splitPageAtCaret(blocks, 1, 3), ['<p>One</p><p>Two</p>', '<p>Three</p>'])
    assert.deepEqual(splitPageAtCaret(blocks, 2, 5), ['<p>One</p><p>Two</p><p>Three</p>', ''])
})

test('New page inside a table, list or picture starts after that block', () => {
    const blocks = ['<p>A</p>', '<ul><li>x</li><li>y</li></ul>', '<p>B</p>']

    assert.deepEqual(splitPageAtCaret(blocks, 1, 1), ['<p>A</p><ul><li>x</li><li>y</li></ul>', '<p>B</p>'])
})

test('overflow moves forward without loss or duplication and creates pages when needed', () => {
    // A page holds three blocks.
    const fit = (html) => {
        const blocks = html.match(/<p>.*?<\/p>/g) ?? []

        return { kept: blocks.slice(0, 3).join(''), moved: blocks.slice(3).join('') }
    }
    const block = (n) => `<p>${n}</p>`
    const pages = [[1, 2, 3].map(block).join(''), [4, 5, 6].map(block).join('')]

    const out = cascadeOverflow(pages, 0, [7, 8].map(block).join(''), fit)

    assert.equal(out.length, 3)
    assert.equal(out[0], [7, 8, 1].map(block).join(''))
    assert.equal(out[1], [2, 3, 4].map(block).join(''))
    assert.equal(out[2], [5, 6].map(block).join(''))
    assert.deepEqual(textOnly(joinPages(out)).split('').sort(), '12345678'.split('').sort())

    // The input array is untouched.
    assert.equal(pages.length, 2)
})

test('nothing carried leaves the pages as they were', () => {
    const pages = ['<p>a</p>', '<p>b</p>']

    assert.deepEqual(cascadeOverflow(pages, 0, '', () => assert.fail('no measuring needed')), pages)
})

test('the last remaining page cannot be removed', () => {
    assert.equal(removePage(['<p>only</p>'], 0), null)
    assert.equal(removePage([''], 0), null)
    assert.deepEqual(removePage(['a', 'b', 'c'], 1), ['a', 'c'])
    assert.equal(removePage(['a', 'b'], 5), null)
})

test('a page is empty when it has no text, picture or table', () => {
    assert.equal(isPageEmpty(''), true)
    assert.equal(isPageEmpty('<p><br></p>'), true)
    assert.equal(isPageEmpty('<p>&nbsp;</p>'), true)
    assert.equal(isPageEmpty('<p>text</p>'), false)
    assert.equal(isPageEmpty('<figure><img src="/documents/images/1"></figure>'), false)
    assert.equal(isPageEmpty('<table><tr><td></td></tr></table>'), false)
})

test('the marker constant is itself recognised by split', () => {
    assert.deepEqual(splitPages(PAGE_BREAK), ['', ''])
})

function textOnly(html) {
    return html.replace(/<[^>]*>/g, '')
}
