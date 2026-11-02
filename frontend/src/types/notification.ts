/** `GET /api/notifications`: one line of work waiting in the user's scope. */
export interface BellNotification {
  key: string
  label: string
  count: number
  to: string
}
