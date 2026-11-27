import { useQuery } from '@tanstack/react-query'
import { Link, matchPath, useLocation, useSearchParams } from 'react-router-dom'
import { BookOpen, CalendarX, ClipboardList, House, NotebookPen, Users } from 'lucide-react'
import { useAuthStore } from '../../store/authStore'
import { fetchMesClasses } from '../../features/portail-enseignant/portailEnseignantApi'
import { fetchMesEnfants } from '../../features/portail-parent/portailParentApi'

/*
 * Barre de navigation du bas, pour le téléphone : quatre entrées à portée de pouce.
 *
 * Elle mène aux onglets des pages existantes (?onglet=…) plutôt qu'à des pages nouvelles :
 * même contenu que sur ordinateur, seulement plus près du doigt. La classe (enseignant) ou
 * l'enfant (parent) visé est celui de la page ouverte, sinon le premier de la liste — le
 * cas courant au primaire, où un maître tient une classe.
 *
 * Masquée à partir de la taille tablette : l'en-tête et les onglets y suffisent.
 */
const ENTREES = {
  enseignant: [
    { cle: 'accueil', libelle: 'Accueil', icone: House },
    { cle: 'eleves', libelle: 'Ma classe', icone: Users },
    { cle: 'notes', libelle: 'Notes', icone: ClipboardList },
    { cle: 'devoirs', libelle: 'Devoirs', icone: NotebookPen },
  ],
  parent: [
    { cle: 'accueil', libelle: 'Accueil', icone: House },
    { cle: 'resultats', libelle: 'Notes', icone: ClipboardList },
    { cle: 'absences', libelle: 'Absences', icone: CalendarX },
    { cle: 'devoirs', libelle: 'Devoirs', icone: BookOpen },
  ],
}

export default function BarreNavigationPortail() {
  const typePortail = useAuthStore((s) => s.typePortail)
  const { pathname } = useLocation()
  // La barre vit dans la mise en page, au-dessus des pages : on lit la classe ou l'enfant
  // ouvert dans l'adresse elle-même.
  const params = {
    classe: matchPath('/mon-espace/classes/:classe', pathname)?.params.classe,
    matricule: matchPath('/mon-espace/enfants/:matricule', pathname)?.params.matricule,
  }
  const [recherche] = useSearchParams()
  const enseignant = typePortail === 'enseignant'

  const { data: classes } = useQuery({
    queryKey: ['mes-classes'], queryFn: fetchMesClasses, enabled: enseignant,
  })
  const { data: enfants } = useQuery({
    queryKey: ['mes-enfants'], queryFn: fetchMesEnfants, enabled: typePortail === 'parent',
  })

  const cible = enseignant
    ? (params.classe ?? classes?.classes?.[0]?.classe)
    : (params.matricule ?? enfants?.enfants?.[0]?.matricule)
  const base = cible
    ? `/mon-espace/${enseignant ? 'classes' : 'enfants'}/${encodeURIComponent(cible)}`
    : null

  const surPageDetail = Boolean(params.classe || params.matricule)
  const ongletCourant = recherche.get('onglet')
  const entrees = ENTREES[typePortail] ?? []

  return (
    <nav
      aria-label="Navigation principale"
      className="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t md:hidden"
      style={{
        background: 'color-mix(in srgb, var(--card) 92%, transparent)',
        backdropFilter: 'blur(10px)',
        borderColor: 'var(--border)',
        paddingBottom: 'env(safe-area-inset-bottom)',
      }}
    >
      {entrees.map(({ cle, libelle, icone: Icone }) => {
        const vers = cle === 'accueil' ? '/mon-espace' : (base ? `${base}?onglet=${cle}` : '/mon-espace')
        const actif = cle === 'accueil'
          ? pathname === '/mon-espace'
          : surPageDetail && ongletCourant === cle
        return (
          <Link
            key={cle}
            to={vers}
            aria-current={actif ? 'page' : undefined}
            className="flex min-h-[60px] flex-col items-center justify-center gap-1 text-[11px] font-bold transition active:scale-95"
            style={{ color: actif ? 'var(--brand-accent)' : 'var(--muted-text)' }}
          >
            <Icone size={22} strokeWidth={actif ? 2.6 : 2} />
            {libelle}
          </Link>
        )
      })}
    </nav>
  )
}
