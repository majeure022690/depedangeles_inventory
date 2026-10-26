import { useId, useRef, useState, type FormEvent } from 'react'

import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/Dialog'
import { Input } from '@/components/ui/Input'
import { extractError, extractFieldErrors, isTooManyRequests, isValidationError, TOO_MANY_ATTEMPTS_MESSAGE } from '@/lib/errors'

interface PasswordConfirmDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onConfirm: (currentPassword: string) => Promise<void>
  onCloseAutoFocus?: (event: Event) => void
  title?: string
  description: string
  confirmText?: string
  confirmVariant?: 'primary' | 'danger'
  fallbackError?: string
}

export function PasswordConfirmDialog({
  open,
  onOpenChange,
  onCloseAutoFocus,
  title = 'Confirm your password',
  ...formProps
}: PasswordConfirmDialogProps) {
  const [submitting, setSubmitting] = useState(false)

  function handleOpenChange(next: boolean) {
    if (!next && submitting) return
    onOpenChange(next)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        size="sm"
        onCloseAutoFocus={onCloseAutoFocus}
        onEscapeKeyDown={(event) => submitting && event.preventDefault()}
        onPointerDownOutside={(event) => submitting && event.preventDefault()}
      >
        <DialogTitle>{title}</DialogTitle>
        <PasswordConfirmForm
          submitting={submitting}
          onSubmittingChange={setSubmitting}
          onClose={() => onOpenChange(false)}
          {...formProps}
        />
      </DialogContent>
    </Dialog>
  )
}

interface PasswordConfirmFormProps extends Omit<PasswordConfirmDialogProps, 'open' | 'onOpenChange' | 'onCloseAutoFocus' | 'title'> {
  submitting: boolean
  onSubmittingChange: (submitting: boolean) => void
  onClose: () => void
}

function PasswordConfirmForm({
  submitting,
  onSubmittingChange,
  onClose,
  onConfirm,
  description,
  confirmText = 'Confirm',
  confirmVariant = 'primary',
  fallbackError = 'Something went wrong. Please try again.',
}: PasswordConfirmFormProps) {
  const inputId = useId()
  const inputRef = useRef<HTMLInputElement>(null)
  const [password, setPassword] = useState('')
  const [passwordError, setPasswordError] = useState<string | undefined>()
  const [formError, setFormError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    onSubmittingChange(true)
    setPasswordError(undefined)
    setFormError(null)
    try {
      await onConfirm(password)
      onSubmittingChange(false)
      onClose()
    } catch (error) {
      onSubmittingChange(false)
      const fieldError = extractFieldErrors(error).current_password
      if (isTooManyRequests(error)) {
        setFormError(TOO_MANY_ATTEMPTS_MESSAGE)
      } else if (isValidationError(error) && fieldError) {
        setPasswordError(fieldError)
        setPassword('')
        inputRef.current?.focus()
      } else {
        setFormError(extractError(error, fallbackError))
      }
    }
  }

  return (
    <>
      <DialogDescription>{description}</DialogDescription>
      <form onSubmit={handleSubmit} className="space-y-3">
        {formError && <Alert type="error" role="alert">{formError}</Alert>}
        <Input
          ref={inputRef}
          id={inputId}
          name="current_password"
          label="Your PRIME password"
          type="password"
          autoComplete="current-password"
          autoFocus
          required
          value={password}
          error={passwordError}
          onChange={(event) => setPassword(event.target.value)}
        />
        <DialogFooter>
          <Button type="button" variant="outline" disabled={submitting} onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" variant={confirmVariant} isLoading={submitting} disabled={!password}>
            {confirmText}
          </Button>
        </DialogFooter>
      </form>
    </>
  )
}
