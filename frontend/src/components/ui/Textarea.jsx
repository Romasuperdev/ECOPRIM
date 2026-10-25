import { forwardRef } from 'react'
import Libelle, { ErreurChamp } from './Libelle'

const Textarea = forwardRef(function Textarea(
  { label, error, className = '', id, rows = 4, icon: Icon, ...props },
  ref
) {
  return (
    <div className="space-y-1">
      <Libelle htmlFor={id}>{label}</Libelle>
      <div className="relative">
        {Icon && <Icon size={16} className="pointer-events-none absolute left-4 top-3 text-muted" />}
        <textarea
          id={id}
          ref={ref}
          rows={rows}
          className={`field field--zone ${Icon ? 'field--icone' : ''}  ${className}`}
          aria-invalid={error ? true : undefined}
          {...props}
        />
      </div>
      <ErreurChamp>{error}</ErreurChamp>
    </div>
  )
})

export default Textarea
