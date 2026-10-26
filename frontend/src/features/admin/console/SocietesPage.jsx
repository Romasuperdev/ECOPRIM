import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, PlusCircle, RotateCcw } from 'lucide-react'
import { activerSocietePlateforme, desactiverSocietePlateforme, fetchToutesSocietes } from '../adminApi'
import {
  Actions, BoutonEntete, BoutonModifier, BoutonSupprimer, CarteConsole, Pastille, TableConsole, flash, messageApi,
} from './kit'

/**
 * Sociétés de la console — la liste parcauto (console/societes) : Code Société, Nom,
 * Email, et les boutons Modifier / Supprimer.
 *
 * Une société est partagée par toute la suite d'applications : elle ne s'efface pas.
 * « Supprimer » la DÉSACTIVE dans ECOPRIM (elle disparaît des choix de société), et elle
 * se réactive d'ici.
 */
export default function SocietesPage() {
  const qc = useQueryClient()
  const { data, isLoading, error } = useQuery({ queryKey: ['societes', 'toutes'], queryFn: fetchToutesSocietes })

  const basculer = useMutation({
    mutationFn: ({ s, activer }) => (activer ? activerSocietePlateforme(s.code) : desactiverSocietePlateforme(s.code)),
    onSuccess: (_, { s, activer }) => {
      qc.invalidateQueries({ queryKey: ['societes'] })
      qc.invalidateQueries({ queryKey: ['console-contexte'] })
      flash.succes(activer ? `La société ${s.nom} a été réactivée.` : `La société ${s.nom} a été désactivée.`)
    },
    onError: (err) => flash.erreur(messageApi(err)),
  })

  const colonnes = [
    { cle: 'code', titre: 'Code Société', className: 'font-semibold' },
    { cle: 'nom', titre: 'Nom' },
    { cle: 'email', titre: 'Email' },
    {
      cle: 'actif', titre: 'État', valeur: (s) => (s.actif ? 'Actif' : 'Inactif'),
      rendu: (s) => <Pastille actif={s.actif} oui="Active" non="Inactive" />,
    },
    {
      cle: 'actions', titre: 'Actions', triable: false, className: 'w-32',
      rendu: (s) => (
        <Actions>
          <BoutonModifier to={`/admin/societes/${encodeURIComponent(s.code)}/modifier`} />
          {s.actif ? (
            <BoutonSupprimer
              disabled={basculer.isPending}
              confirmation={`Supprimer la société ${s.nom} ? Elle sera désactivée et pourra être réactivée.`}
              onConfirme={() => basculer.mutate({ s, activer: false })}
            />
          ) : (
            <button
              type="button" title="Réactiver" aria-label="Réactiver" disabled={basculer.isPending}
              onClick={() => basculer.mutate({ s, activer: true })}
              className="inline-flex h-8 w-8 items-center justify-center rounded-md border transition hover:-translate-y-0.5 hover:shadow disabled:opacity-50"
              style={{ borderColor: 'var(--success-border)', color: 'var(--success-text)' }}
            >
              <RotateCcw size={15} />
            </button>
          )}
        </Actions>
      ),
    },
  ]

  return (
    <CarteConsole
      icon={Building2}
      titre="Liste des Sociétés"
      action={<BoutonEntete to="/admin/societes/nouvelle" icon={PlusCircle}>Nouvelle Société</BoutonEntete>}
    >
      <TableConsole
        colonnes={colonnes}
        lignes={data}
        cleLigne={(s) => s.code}
        chargement={isLoading}
        erreur={error}
        vide="Aucune société enregistrée."
      />
    </CarteConsole>
  )
}
