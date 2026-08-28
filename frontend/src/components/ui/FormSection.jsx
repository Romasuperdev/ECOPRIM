export default function FormSection({ icon: Icon, title, description, children, className = '' }) {
  return (
    <div className={`space-y-4 ${className}`}>
      <div className="flex items-center gap-2 border-b border-slate-100 pb-2">
        {Icon && (
          <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-50 text-primary-700">
            <Icon size={15} />
          </span>
        )}
        <div>
          <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
          {description && <p className="text-xs text-slate-400">{description}</p>}
        </div>
      </div>
      <div className="space-y-4">{children}</div>
    </div>
  )
}
