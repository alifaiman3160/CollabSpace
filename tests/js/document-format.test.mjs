import test from 'node:test'
import assert from 'node:assert/strict'

import {
    parseStyle, withStyle, commonValue, clampLineHeight, spacingChoice, spacingOf, stepIndent, applyToBlocks,
    firstLineChanges, indentOf, spaceOf, sizeStep, hexOf, lengthOf, COMPACT, isCompact,
} from '../../resources/js/document-format.js'
import { peekPlan } from '../../resources/js/document-pages.js'

test('styles are parsed and rewritten without losing other properties', () => {
    assert.deepEqual(parseStyle('color: #111; line-height: 1.5'), { color: '#111', 'line-height': '1.5' })
    assert.equal(withStyle('color: #111', { 'line-height': '2' }), 'color: #111; line-height: 2')
    assert.equal(withStyle('color: #111; line-height: 2', { 'line-height': null }), 'color: #111')
    assert.equal(withStyle('', { 'margin-top': '6pt' }), 'margin-top: 6pt')
})

test('paragraph formatting applies to every selected block and to no others', () => {
    const blocks = [{ style: '' }, { style: 'color: red' }, { style: 'line-height: 1' }, { style: '' }]
    const out = applyToBlocks(blocks, [1, 2], { 'line-height': '2' })

    assert.equal(out[0].style, '')
    assert.equal(out[1].style, 'color: red; line-height: 2')
    assert.equal(out[2].style, 'line-height: 2')
    assert.equal(out[3].style, '')
    // The originals are untouched.
    assert.equal(blocks[1].style, 'color: red')
})

test('character formatting is a property of the selected text, never of the blocks', () => {
    // Applying a paragraph change to an empty selection list changes nothing at all.
    const blocks = [{ style: 'a: b' }, { style: '' }]

    assert.deepEqual(applyToBlocks(blocks, [], { 'text-indent': '1.25cm' }), blocks)
})

test('the Spacing control reports the current preset, a custom value, or mixed', () => {
    assert.deepEqual(spacingOf([1.5, 1.5]), { value: '1.5', custom: null })
    assert.deepEqual(spacingOf([1]), { value: '1', custom: null })
    assert.deepEqual(spacingOf([1.15, 1.15, 1.15]), { value: '1.15', custom: null })
    assert.deepEqual(spacingOf([2]), { value: '2', custom: null })
    assert.deepEqual(spacingOf([1.5, 2]), { value: 'mixed', custom: null })
    assert.deepEqual(spacingOf([1.3]), { value: 'custom', custom: 1.3 })
    assert.deepEqual(spacingOf([]), { value: '', custom: null })
    assert.deepEqual(spacingChoice(1.4999999), { value: '1.5', custom: null })
})

test('mixed values are reported as mixed and empty as blank', () => {
    assert.equal(commonValue(['a', 'a']), 'a')
    assert.equal(commonValue(['a', 'b']), 'mixed')
    assert.equal(commonValue([]), '')
})

test('a custom line spacing must be between 1.0 and 3.0', () => {
    assert.equal(clampLineHeight('1.25'), 1.25)
    assert.equal(clampLineHeight('1,5'), 1.5)
    assert.equal(clampLineHeight('3'), 3)
    assert.equal(clampLineHeight('0.9'), null)
    assert.equal(clampLineHeight('3.1'), null)
    assert.equal(clampLineHeight('abc'), null)
    assert.equal(clampLineHeight(''), null)
})

test('indent steps by a centimetre, never below 0 or above the maximum', () => {
    assert.equal(stepIndent(0, 1), 1)
    assert.equal(stepIndent(1, 1), 2)
    assert.equal(stepIndent(0, -1), 0)
    assert.equal(stepIndent(10, 1), 10)
    assert.equal(stepIndent(2.5, -1), 1.5)
})

test('indent, space before and after read from several blocks', () => {
    const blocks = [{ style: 'margin-left: 2cm; margin-top: 6pt' }, { style: 'margin-left: 2cm; margin-top: 12pt' }]

    assert.equal(indentOf(blocks), 2)
    assert.equal(spaceOf(blocks, 'margin-top'), 'mixed')
    assert.equal(spaceOf(blocks, 'margin-bottom'), '')
    assert.equal(spaceOf([blocks[0]], 'margin-top'), '6')
    assert.equal(indentOf([{ style: '' }, { style: 'margin-left: 1cm' }]), 'mixed')
})

test('the first-line indent toggles on, then off when every block already has it', () => {
    assert.deepEqual(firstLineChanges([{ style: '' }, { style: 'text-indent: 1.25cm' }]), { 'text-indent': '1.25cm' })
    assert.deepEqual(firstLineChanges([{ style: 'text-indent: 1.25cm' }, { style: 'text-indent: 1.25cm' }]), { 'text-indent': null })
    assert.deepEqual(firstLineChanges([]), { 'text-indent': '1.25cm' })
})

test('sizes and colours are read back in the editor\'s own terms', () => {
    assert.equal(sizeStep(16), '3')
    assert.equal(sizeStep(24), '5')
    assert.equal(sizeStep(17), '')
    assert.equal(hexOf('rgb(17, 17, 20)'), '#111114')
    assert.equal(hexOf('rgba(0, 0, 0, 0)'), '')
    assert.equal(hexOf('transparent'), '')
    assert.equal(lengthOf('1.25cm', 'cm'), 1.25)
    assert.equal(lengthOf('bad', 'cm'), 0)
})

test('the book look never shows more than two miniatures or three edge layers a side', () => {
    for (const total of [1, 2, 3, 5, 8, 30]) {
        for (let current = 0; current < total; current++) {
            const plan = peekPlan(current, total)
            const miniatures = [plan.previous, plan.next].filter((index) => index !== null).length

            assert.ok(miniatures <= 2)
            assert.ok(plan.edgesLeft >= 0 && plan.edgesLeft <= 3)
            assert.ok(plan.edgesRight >= 0 && plan.edgesRight <= 3)
        }
    }
})

test('nothing on the left of page 1, nothing on the right of the last, and a new sheet there', () => {
    assert.deepEqual(peekPlan(0, 5), { previous: null, next: 1, edgesLeft: 0, edgesRight: 3, newSheet: false })
    assert.deepEqual(peekPlan(4, 5), { previous: 3, next: null, edgesLeft: 3, edgesRight: 0, newSheet: true })
    assert.deepEqual(peekPlan(0, 1), { previous: null, next: null, edgesLeft: 0, edgesRight: 0, newSheet: true })
    assert.deepEqual(peekPlan(1, 3), { previous: 0, next: 2, edgesLeft: 0, edgesRight: 0, newSheet: false })
    assert.deepEqual(peekPlan(10, 30), { previous: 9, next: 11, edgesLeft: 3, edgesRight: 3, newSheet: false })
})

test('Compact is single spacing with no gap between paragraphs, and is recognised again', () => {
    const out = applyToBlocks([{ style: 'color: red' }, { style: '' }], [0, 1], COMPACT)

    assert.equal(out[0].style, 'color: red; line-height: 1; margin-top: 0pt; margin-bottom: 0pt')
    assert.ok(out.every((block) => isCompact(block.style)))
    assert.equal(isCompact('line-height: 1.5'), false)
    assert.equal(isCompact('line-height: 1; margin-top: 0pt'), false)
    assert.equal(isCompact(''), false)
})
