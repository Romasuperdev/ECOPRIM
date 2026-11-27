import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Bell } from 'lucide-react'
import { fetchCompteurNotifications } from '../../features/notifications/notificationsApi'
import { majPastilleApplication } from '../../lib/pwa'

/** Cloche de l'en-tête des portails, avec le nombre de notifications non lues. */
export default function ClocheNotifications() {
  const { data } = useQuery({
    queryKey: ['notifications-compteur'],
    queryFn: fetchCompteurNotifications,
    // Assez souvent pour qu'une absence signalée se voie dans la minute, sans plus :
    // le push, lui, prévient tout de suite.
    refetchInterval: 60_000,
    refetchOnWindowFocus: true,
  })
  const nombre = data?.non_lues ?? 0

  useEffect(() => { majPastilleApplication(nombre) }, [nombre])

  return (
    <Link
      to="/mon-espace/notifications"
      className="relative inline-flex h-10 w-10 items-center justify-center rounded-full transition hover:bg-[var(--surface-2)]"
      aria-label={nombre ? `${nombre} notification${nombre > 1 ? 's' : ''} non lue${nombre > 1 ? 's' : ''}` : 'Notifications'}
      title="Notifications"
    >
      <Bell size={20} style={{ color: 'var(--heading)' }} />
      {nombre > 0 && (
        <span
          className="absolute right-1 top-1 flex min-w-[18px] items-center justify-center rounded-full px-1 text-[10px] font-extrabold leading-[18px]"
          style={{ background: 'var(--danger)', color: '#fff' }}
        >
          {nombre > 99 ? '99+' : nombre}
        </span>
      )}
    </Link>
  )
}
