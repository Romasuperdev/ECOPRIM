import { forwardRef } from 'react'

const Textarea = forwardRef(function Textarea(
  { label, error, className = '', id, rows = 4, icon: Icon, ...props },
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
        {Icon && <Icon size={16} className="pointer-events-none absolute left-3 top-2.5 text-muted" />}
        <textarea
          id={id}
          ref={ref}
          rows={rows}
          className={`field ${Icon ? 'pl-9' : ''} ${error ? '!border-red-400' : ''} ${className}`}
          {...props}
        />
      </div>
      {error && <p className="text-sm text-red-600">{error}</p>}
    </div>
  )
})

export default Textarea
