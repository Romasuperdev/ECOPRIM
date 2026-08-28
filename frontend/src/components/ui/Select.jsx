import { forwardRef } from 'react'

const Select = forwardRef(function Select(
  { label, error, className = '', id, icon: Icon, children, ...props },
  ref
) {
  return (
    <div className="space-y-1">
      {label && (
        <label htmlFor={id} className="mb-1.5 block text-sm font-bold text-heading">
          {label}
        </label>
      )}
      <div className="relative">
        {Icon && (
          <Icon size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
        )}
        <select
          id={id}
          ref={ref}
          className={`field ${Icon ? 'pl-9' : ''} ${error ? '!border-red-400' : ''} ${className}`}
          {...props}
        >
          {children}
        </select>
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}
    </div>
  )
})

export default Select
