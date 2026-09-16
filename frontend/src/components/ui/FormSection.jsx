/**
 * En-tête de section dans un formulaire.
 *
 * @param {ReactNode} actions  Zone d'action alignée à droite du titre — c'est là
 *   que se place un « + Ajouter une autre ligne ». Le titre et son action se
 *   lisent ensemble ; reléguer l'action en bas de section la rendrait invisible.
 */
export default function FormSection({
  icon: Icon,
  title,
  description,
  actions,
  children,
  className = '',
}) {
  return (
    <div className={`space-y-4 ${className}`}>
      <div className="flex items-center justify-between gap-3 border-b pb-2.5">
        <div className="flex min-w-0 items-center gap-2">
          {Icon && (
            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary-100 text-primary-800">
              <Icon size={15} />
            </span>
          )}
          <div className="min-w-0">
            <h2 className="truncate text-base font-bold text-heading">{title}</h2>
            {description && <p className="text-xs font-medium text-muted">{description}</p>}
          </div>
        </div>
        {actions && <div className="shrink-0">{actions}</div>}
      </div>
      <div className="space-y-4">{children}</div>
    </div>
  )
}
