import test from 'node:test'
import assert from 'node:assert/strict'

import { place, splitShortcut, isExcluded, hasAccessibleName, upgradePlan } from '../../resources/js/tooltip-logic.js'

const viewport = { width: 1000, height: 700 }
const tip = { width: 200, height: 40 }

test('a tooltip goes above its trigger, centred, with the arrow under its middle', () => {
    const spot = place({ left: 400, top: 300, width: 100, height: 30 }, tip, viewport)

    assert.equal(spot.side, 'top')
    assert.equal(spot.top, 300 - 40 - 8)
    assert.equal(spot.left, 450 - 100)
    assert.equal(spot.arrow, 100)
})

test('with no room above it flips below', () => {
    const spot = place({ left: 400, top: 10, width: 100, height: 30 }, tip, viewport)

    assert.equal(spot.side, 'bottom')
    assert.equal(spot.top, 10 + 30 + 8)
})

test('it is kept inside the window at either edge, and the arrow still points at the trigger', () => {
    const first = place({ left: 0, top: 300, width: 30, height: 20 }, tip, viewport)

    assert.equal(first.left, 8)
    assert.ok(first.arrow >= 14 && first.arrow <= 200 - 14)
    assert.equal(first.arrow, 14, 'The trigger is near the left end, so the arrow is too.')

    const last = place({ left: 970, top: 300, width: 30, height: 20 }, tip, viewport)

    assert.equal(last.left, 1000 - 200 - 8)
    assert.equal(last.arrow, 200 - 14)
})

test('it never leaves the window vertically, even in a very short one', () => {
    const spot = place({ left: 400, top: 20, width: 40, height: 20 }, { width: 200, height: 60 }, { width: 800, height: 90 })

    assert.ok(spot.top >= 8)
    assert.ok(spot.top + 60 <= 90 - 8 + 0.0001 || spot.top === 8)
})

test('a key combination in brackets becomes key chips, any other bracket stays text', () => {
    assert.deepEqual(splitShortcut('Bold (Ctrl + B)'), { text: 'Bold', keys: ['Ctrl', 'B'] })
    assert.deepEqual(splitShortcut('Redo (Ctrl + Shift + Z)'), { text: 'Redo', keys: ['Ctrl', 'Shift', 'Z'] })
    assert.deepEqual(splitShortcut('Previous page (Alt + Left)'), { text: 'Previous page', keys: ['Alt', 'Left'] })
    assert.deepEqual(splitShortcut('Delete (cannot be undone)'), { text: 'Delete (cannot be undone)', keys: [] })
    assert.deepEqual(splitShortcut('Plain'), { text: 'Plain', keys: [] })
    assert.deepEqual(splitShortcut('Page (1 + 2)'), { text: 'Page (1 + 2)', keys: [] })
})

test('beside a trigger it goes to the right, level with it, or to the left at the window edge', () => {
    const spot = place({ left: 0, top: 100, width: 48, height: 44 }, { width: 120, height: 32 }, viewport, 8, 8, 'right')

    assert.equal(spot.side, 'right')
    assert.equal(spot.left, 56)
    assert.equal(spot.top, 100 + 22 - 16)
    assert.equal(spot.arrow, 16)

    const edge = place({ left: 960, top: 100, width: 30, height: 30 }, { width: 120, height: 32 }, viewport, 8, 8, 'right')
    assert.equal(edge.side, 'left')
    assert.equal(edge.left, 960 - 8 - 120)

    const low = place({ left: 0, top: 690, width: 48, height: 44 }, { width: 120, height: 32 }, viewport, 8, 8, 'right')
    assert.equal(low.top, 700 - 32 - 8)
})

const fake = (attrs = {}, text = '', { tag = 'BUTTON', inside = [] } = {}) => ({
    tagName: tag,
    textContent: text,
    hasAttribute: (name) => name in attrs,
    getAttribute: (name) => attrs[name] ?? null,
    querySelector: () => null,
    closest: (selector) => (inside.some((part) => selector.includes(part)) ? {} : null),
})

test('user content, frames and anything that opts out are left alone', () => {
    assert.equal(isExcluded(fake({ title: 'x' }, '', { inside: ['.doc-body'] })), true)
    assert.equal(isExcluded(fake({ title: 'x' }, '', { inside: ['data-no-tooltip'] })), true)
    assert.equal(isExcluded(fake({ title: 'x' }, '', { inside: ['contenteditable'] })), true)
    assert.equal(isExcluded(fake({ title: 'x' }, '', { tag: 'IFRAME' })), true)
    assert.equal(isExcluded(fake({ title: 'x' }, '', { tag: 'TITLE' })), true)
    assert.equal(isExcluded(fake({ title: 'x' })), false)
})

test('an element that already has a name does not get a second one', () => {
    assert.equal(hasAccessibleName(fake({ 'aria-label': 'Delete' })), true)
    assert.equal(hasAccessibleName(fake({}, 'Save')), true)
    assert.equal(hasAccessibleName(fake({}, '   ')), false)
})

test('the text upgrade moves the title to a tooltip and names only icon-only elements', () => {
    assert.deepEqual(upgradePlan(fake({ title: 'Download PDF' })), { text: 'Download PDF', aria: 'Download PDF' })
    assert.deepEqual(upgradePlan(fake({ title: 'Bold (Ctrl + B)' })), { text: 'Bold (Ctrl + B)', aria: 'Bold' })
    assert.deepEqual(upgradePlan(fake({ title: 'Full name' }, 'AB')), { text: 'Full name', aria: null })
    assert.deepEqual(upgradePlan(fake({ title: 'Go', 'aria-label': 'Go to page' })), { text: 'Go', aria: null })
    assert.equal(upgradePlan(fake({ title: 'Mine' }, '', { inside: ['.doc-body'] })), null)
    assert.equal(upgradePlan(fake({})), null)
    assert.deepEqual(upgradePlan(fake({ title: '  ' })), { text: '', aria: null })
})
