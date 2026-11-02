import test from 'node:test'
import assert from 'node:assert/strict'

import { decideCollapsed, parseOpen, serializeOpen, withSection, matchesPath, activeChild, nextIndex, placeFlyout } from '../../resources/js/sidebar-logic.js'

test('expanded by default, collapsed on a narrow screen only when nobody has chosen', () => {
    assert.equal(decideCollapsed({ width: 1440 }), false)
    assert.equal(decideCollapsed({ width: 1000 }), true)
    assert.equal(decideCollapsed({ width: 1199 }), true)
    assert.equal(decideCollapsed({ width: 1200 }), false)
    assert.equal(decideCollapsed({}), false)
})

test('an explicit choice always wins, the cookie before the stored copy', () => {
    assert.equal(decideCollapsed({ cookie: 'expanded', width: 800 }), false)
    assert.equal(decideCollapsed({ cookie: 'collapsed', width: 1900 }), true)
    assert.equal(decideCollapsed({ stored: 'expanded', width: 800 }), false)
    assert.equal(decideCollapsed({ cookie: 'collapsed', stored: 'expanded', width: 1900 }), true)
    assert.equal(decideCollapsed({ cookie: 'nonsense', width: 800 }), true, 'Anything else is not a choice.')
})

test('the open sections are read and written as a plain list', () => {
    assert.deepEqual(parseOpen('marking,reports'), ['marking', 'reports'])
    assert.deepEqual(parseOpen('marking,,marking, <b>,Reports'), ['marking'])
    assert.deepEqual(parseOpen(null), [])
    assert.equal(serializeOpen(['a', 'b', 'a']), 'a,b')
    assert.deepEqual(withSection(['a'], 'b', true), ['b'], 'opening one closes the others')
    assert.deepEqual(withSection([], 'b', true), ['b'])
    assert.deepEqual(withSection(['a', 'b'], 'a', false), ['b'])
    assert.deepEqual(withSection(['a'], 'a', true), ['a'])
})

test('a page matches its own address and the pages below it, never a neighbour with the same start', () => {
    assert.equal(matchesPath('/lecturer/progress/3', '/lecturer/progress/3'), true)
    assert.equal(matchesPath('/lecturer/progress/3/documents/9', '/lecturer/progress/3'), true)
    assert.equal(matchesPath('/lecturer/progress/30', '/lecturer/progress/3'), false)
    assert.equal(matchesPath('/lecturer/progress/3/documents', '/lecturer/progress/3', true), false)
    assert.equal(matchesPath('/x', null), false)
})

test('the longest matching sub-item is the active one, and none when the list page itself is open', () => {
    const children = [
        { match: '/lecturer/progress/1' },
        { match: '/lecturer/progress/2' },
        { match: null },
    ]

    assert.equal(activeChild('/lecturer/progress/2', children), 1)
    assert.equal(activeChild('/lecturer/progress/2/documents/5', children), 1)
    assert.equal(activeChild('/lecturer/progress', children), null)
    assert.equal(activeChild('/lecturer/progress/9', children), null)
    assert.equal(activeChild('/lecturer/progress/1', [{ match: '/lecturer/progress' }, { match: '/lecturer/progress/1' }]), 1)
})

test('arrow keys move through the list and wrap round', () => {
    assert.equal(nextIndex(-1, 3, 'ArrowDown'), 0)
    assert.equal(nextIndex(2, 3, 'ArrowDown'), 0)
    assert.equal(nextIndex(0, 3, 'ArrowUp'), 2)
    assert.equal(nextIndex(1, 3, 'Home'), 0)
    assert.equal(nextIndex(1, 3, 'End'), 2)
    assert.equal(nextIndex(1, 3, 'a'), null)
    assert.equal(nextIndex(0, 0, 'ArrowDown'), null)
})

const rail = { left: 0, top: 0, width: 76, height: 900 }
const viewport = { width: 1440, height: 900 }
const card = { width: 200, height: 160 }

test('the floating card sits right of the rail with its top level with the icon', () => {
    const spot = placeFlyout(rail, { left: 14, top: 243, width: 48, height: 44 }, card, viewport)

    assert.equal(spot.left, 84)
    assert.equal(spot.top, 243)
    assert.equal(spot.arrow, 243 + 22 - 243)
})

test('it is shifted up when it would pass the bottom, and down when it would pass the top', () => {
    const low = placeFlyout(rail, { left: 14, top: 850, width: 48, height: 44 }, card, viewport)

    assert.equal(low.top, 900 - 160 - 12)
    assert.ok(low.top + 160 <= 900 - 12)
    assert.ok(low.arrow >= 16 && low.arrow <= 160 - 16, 'The notch stays on the card and still points at the icon.')

    const high = placeFlyout(rail, { left: 14, top: -30, width: 48, height: 44 }, card, viewport)
    assert.equal(high.top, 12)
})

test('it never leaves the window, even a card taller than the window', () => {
    const tall = placeFlyout(rail, { left: 14, top: 400, width: 48, height: 44 }, { width: 200, height: 2000 }, viewport)

    assert.equal(tall.top, 12)

    for (const top of [-500, 0, 100, 450, 899, 2000]) {
        const spot = placeFlyout(rail, { left: 14, top, width: 48, height: 44 }, card, viewport)

        assert.ok(spot.top >= 12 && spot.top + 160 <= 900 - 12, `top ${top} gave ${spot.top}`)
    }
})
