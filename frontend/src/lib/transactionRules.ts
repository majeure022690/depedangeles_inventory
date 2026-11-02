/** Mirrors ValidatesEquipmentTransactions: which fields a transaction type requires. */
export const WASTE_MATERIAL_REPORT = 'Waste Material Report (WMR)'

export function transactionRequirements(type: string) {
  return {
    officer: ['Beginning Inventory', 'Delivery', 'Issuance/Transfer'].includes(type),
    receiver: ['Issuance/Transfer', 'Return'].includes(type),
    documents: type !== '' && type !== 'Beginning Inventory',
    wmr: type === 'Disposal',
  }
}
