import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { IdCard, PlusCircle, RotateCcw, Users } from 'lucide-react'
import { useAuthStore } from '../../../store/authStore'
import { activerUtilisateur, desactiverUtilisateur, fetchTousUtilisateurs } from '../adminApi'
import {
  Actions, BoutonEntete, BoutonModifier, BoutonSupprimer, CarteConsole, Pastille, TableConsole, flash, messageApi,
} from './kit'

/**
 * Utilisateurs de la console — la liste parcauto (console/rh-users) : Login, Nom,
 * Prénom, Email, Profil, et les boutons Modifier / Supprimer.
 *
 * « Supprimer » DÉSACTIVE le compte (RH_USER.Supprimer = 1) : il ne peut plus se
 * connecter, mais il reste en base — d'autres applications de la suite le partagent. Un
 * compte désactivé se réactive d'ici. Les rôles et affectations, eux, se gèrent sur la
 * fiche du compte, que le bouton « Fiche » ouvre.
 */
export default function UtilisateursPage() {
  const superAdmin = useAuthStore((s) => s.superAdmin)
  const qc = useQueryClient()

  const { data, isLoading, error } = useQuery({ queryKey: ['utilisateurs', 'tous'], queryFn: fetchTousUtilisateurs })

  const basculer = useMutation({
    mutationFn: ({ u, activer }) => (activer ? activerUtilisateur(u.id) : desactiverUtilisateur(u.id)),
    onSuccess: (_, { u, activer }) => {
      qc.invalidateQueries({ queryKey: ['utilisateurs'] })
      flash.succes(activer ? `Le compte ${u.login} a été réactivé.` : `Le compte ${u.login} a été désactivé.`)
    },
    onError: (e) => flash.erreur(messageApi(e)),
  })

  // Un compte Super Admin ne se gère qu'entre Super Admins : le serveur le refuserait.
  const intouchable = (u) => u.super_admin && !superAdmin

  const colonnes = [
    {
      cle: 'login', titre: 'Login', className: 'font-semibold',
      rendu: (u) => (
        <>
          {u.login ?? '—'}
          {u.super_admin && (
            <span className="ml-2 rounded-full px-2 py-0.5 text-xs font-bold" style={{ background: 'var(--warning-bg)', color: 'var(--warning-text)' }}>
              Super Admin
            </span>
          )}
        </>
      ),
    },
    { cle: 'nom', titre: 'Nom' },
    { cle: 'prenom', titre: 'Prénom' },
    { cle: 'email', titre: 'Email' },
    {
      cle: 'profil', titre: 'Profil',
      rendu: (u) => (
        <span className="rounded-full px-3 py-1 text-xs font-semibold" style={{ background: 'var(--surface-2)', color: 'var(--text)' }}>
          {u.profil || '-'}
        </span>
      ),
    },
    {
      cle: 'actif', titre: 'État', valeur: (u) => (u.actif ? 'Actif' : 'Inactif'),
      rendu: (u) => <Pastille actif={u.actif} />,
    },
    {
      cle: 'actions', titre: 'Actions', triable: false, className: 'w-40',
      rendu: (u) => (
        <Actions>
          <Link
            to={`/admin/utilisateurs/${u.id}`} title="Fiche : rôles et affectations" aria-label="Fiche"
            className="inline-flex h-8 w-8 items-center justify-center rounded-md border transition hover:-translate-y-0.5 hover:shadow"
            style={{ borderColor: 'var(--brand-accent)', color: 'var(--brand-accent)' }}
          >
            <IdCard size={15} />
          </Link>
          {!intouchable(u) && <BoutonModifier to={`/admin/utilisateurs/${u.id}/modifier`} />}
          {!intouchable(u) && (u.actif ? (
            <BoutonSupprimer
              disabled={basculer.isPending}
              confirmation={`Supprimer l’utilisateur ${u.login} ? Le compte sera désactivé (il ne pourra plus se connecter) et pourra être réactivé.`}
              onConfirme={() => basculer.mutate({ u, activer: false })}
            />
          ) : (
            <button
              type="button" title="Réactiver" aria-label="Réactiver" disabled={basculer.isPending}
              onClick={() => basculer.mutate({ u, activer: true })}
              className="inline-flex h-8 w-8 items-center justify-center rounded-md border transition hover:-translate-y-0.5 hover:shadow disabled:opacity-50"
              style={{ borderColor: 'var(--success-border)', color: 'var(--success-text)' }}
            >
              <RotateCcw size={15} />
            </button>
          ))}
        </Actions>
      ),
    },
  ]

  return (
    <CarteConsole
      icon={Users}
      titre="Liste des Utilisateurs"
      action={<BoutonEntete to="/admin/utilisateurs/nouveau" icon={PlusCircle}>Nouvel Utilisateur</BoutonEntete>}
    >
      <TableConsole
        colonnes={colonnes}
        lignes={data}
        chargement={isLoading}
        erreur={error}
        vide="Aucun utilisateur enregistré."
      />
    </CarteConsole>
  )
}
