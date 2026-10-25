import { forwardRef } from 'react'
import Libelle, { ErreurChamp } from './Libelle'

const Input = forwardRef(function Input(
  { label, error, className = '', id, icon: Icon, rightElement, ...props },
  ref
) {
  return (
    <div className="space-y-1">
      <Libelle htmlFor={id}>{label}</Libelle>
      <div className="relative">
        {Icon && (
          <Icon size={16} className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-muted" />
        )}
        <input
          id={id}
          ref={ref}
          className={`field ${Icon ? 'field--icone' : ''} ${rightElement ? 'field--action' : ''} ${
            error ? '!border-red-400' : ''
          } ${className}`}
          aria-invalid={error ? true : undefined}
          {...props}
        />
        {rightElement && (
          <div className="absolute right-4 top-1/2 -translate-y-1/2">{rightElement}</div>
        )}
      </div>
      <ErreurChamp>{error}</ErreurChamp>
    </div>
  )
})

export default Input
