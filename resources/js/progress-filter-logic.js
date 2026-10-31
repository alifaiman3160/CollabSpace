/*
 * The Monitor progress tiles, worked out from the cards that are showing: how many groups, how many need attention
 * (any overdue task), the overdue tasks in all, and the average of the groups' progress. A card is
 * { subject, overdue, progress } with progress a whole percent. Pure, so it is tested with Node.
 */
export const visibleIn = (cards, subject) => cards.filter((card) => subject === null || String(card.subject) === String(subject))

export const tiles = (cards) => ({
    groups: cards.length,
    attention: cards.filter((card) => Number(card.overdue) > 0).length,
    overdue: cards.reduce((sum, card) => sum + Number(card.overdue), 0),
    average: cards.length === 0 ? 0 : Math.round(cards.reduce((sum, card) => sum + Number(card.progress), 0) / cards.length),
})