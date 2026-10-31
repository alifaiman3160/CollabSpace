import test from 'node:test'
import assert from 'node:assert/strict'

import { tiles, visibleIn } from '../../resources/js/progress-filter-logic.js'

const cards = [
    { subject: '1', overdue: '2', progress: '0' },
    { subject: '1', overdue: '0', progress: '50' },
    { subject: '2', overdue: '1', progress: '100' },
]

test('the subject pills show only that subject, or everything for none', () => {
    assert.equal(visibleIn(cards, null).length, 3)
    assert.equal(visibleIn(cards, 1).length, 2)
    assert.equal(visibleIn(cards, '2').length, 1)
    assert.equal(visibleIn(cards, 9).length, 0)
})

test('the tiles follow what is showing', () => {
    assert.deepEqual(tiles(cards), { groups: 3, attention: 2, overdue: 3, average: 50 })
    assert.deepEqual(tiles(visibleIn(cards, 1)), { groups: 2, attention: 1, overdue: 2, average: 25 })
    assert.deepEqual(tiles([]), { groups: 0, attention: 0, overdue: 0, average: 0 })
})
