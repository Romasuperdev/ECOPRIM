import { forwardRef } from 'react'
import Libelle, { ErreurChamp } from './Libelle'

const Select = forwardRef(function Select(
  { label, error, className = '', id, icon: Icon, children, ...props },
  ref
) {
  return (
    <div className="space-y-1">
      <Libelle htmlFor={id}>{label}</Libelle>
      <div className="relative">
        {Icon && (
          <Icon size={16} className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-muted" />
        )}
        <select
          id={id}
          ref={ref}
          className={`field ${Icon ? 'field--icone' : ''}  ${className}`}
          aria-invalid={error ? true : undefined}
          {...props}
        >
          {children}
        </select>
      </div>
      <ErreurChamp>{error}</ErreurChamp>
    </div>
  )
})

export default Select
