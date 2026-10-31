import test from 'node:test'
import assert from 'node:assert/strict'

import { matches, arrange, filtered } from '../../resources/js/document-grid-logic.js'

const docs = [
    { title: 'Chapter 1 Introduction', edited: 300 },
    { title: 'appendix', edited: 100 },
    { title: 'Budget', edited: 200 },
]

test('search matches titles case-insensitively and ignores surrounding spaces', () => {
    assert.equal(matches('Chapter 1 Introduction', 'INTRO'), true)
    assert.equal(matches('Chapter 1 Introduction', '  chapter '), true)
    assert.equal(matches('Budget', 'chap'), false)
    assert.equal(matches('Budget', ''), true)
    assert.equal(matches('Budget', null), true)
})

test('filtering reports who shows, how many, and hides the New document tile while searching', () => {
    assert.deepEqual(filtered(docs, 'b'), { visible: [false, false, true], shown: 1, tile: false })
    assert.deepEqual(filtered(docs, 'zzz'), { visible: [false, false, false], shown: 0, tile: false })
    assert.deepEqual(filtered(docs, ''), { visible: [true, true, true], shown: 3, tile: true })
    assert.equal(filtered(docs, '   ').tile, true)
})

test('sorting is newest edit first, or title A to Z ignoring case', () => {
    assert.deepEqual(arrange(docs, 'edited').map((d) => d.title), ['Chapter 1 Introduction', 'Budget', 'appendix'])
    assert.deepEqual(arrange(docs, 'title').map((d) => d.title), ['appendix', 'Budget', 'Chapter 1 Introduction'])
    // The input is untouched.
    assert.equal(docs[0].title, 'Chapter 1 Introduction')
})