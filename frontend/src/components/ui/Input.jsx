import { forwardRef } from 'react'

const Input = forwardRef(function Input(
  { label, error, className = '', id, icon: Icon, rightElement, ...props },
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
        <input
          id={id}
          ref={ref}
          className={`field ${Icon ? 'pl-9' : ''} ${rightElement ? 'pr-9' : ''} ${
            error ? '!border-red-400' : ''
          } ${className}`}
          {...props}
        />
        {rightElement && (
          <div className="absolute right-3 top-1/2 -translate-y-1/2">{rightElement}</div>
        )}
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}
    </div>
  )
})

export default Input
