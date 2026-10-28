export const pesoExact = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2, maximumFractionDigits: 2 })

export function formatPercentage(value: number): string {
  return `${value.toLocaleString('en-PH', { maximumFractionDigits: 1 })}%`
}
