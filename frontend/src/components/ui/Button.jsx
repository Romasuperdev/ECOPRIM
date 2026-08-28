export default function Button({ children, variant = 'primary', className = '', ...props }) {
  const base =
    'px-4 py-2 rounded-xl font-semibold text-sm transition disabled:opacity-50 active:scale-[.98] inline-flex items-center gap-2'

  const styles = {
    primary: { background: 'var(--sidebar)', color: '#fff' },
    gold: { background: 'var(--accent)', color: 'var(--accent-ink)' },
    secondary: { background: 'var(--accent)', color: 'var(--accent-ink)' },
    danger: { background: '#dc2626', color: '#fff' },
  }

  if (variant === 'outline' || variant === 'ghost') {
    return (
      <button
        className={`${base} ${className}`}
        style={{ background: 'var(--surface-2)', color: 'var(--text)' }}
        {...props}
      >
        {children}
      </button>
    )
  }

  return (
    <button className={`${base} ${className}`} style={styles[variant] || styles.primary} {...props}>
      {children}
    </button>
  )
}
