import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building, PlusCircle, RotateCcw } from 'lucide-react'
import { activerEtablissement, desactiverEtablissement, fetchTousEtablissements } from '../adminApi'
import {
  Actions, BoutonEntete, BoutonModifier, BoutonSupprimer, CarteConsole, Pastille, TableConsole, flash, messageApi,
} from './kit'

/**
 * Établissements de la console — la liste parcauto (console/etablissements) : Code,
 * Nom, Société, Ville, Téléphone, et les boutons Modifier / Supprimer.
 *
 * Un établissement ne se supprime pas : « Supprimer » le DÉSACTIVE, et il se réactive
 * d'ici. Un établissement lu dans la base métier mais pas encore repris dans ECOPRIM n'a
 * pas d'état propre à basculer : « Modifier » le reprend d'abord.
 */
export default function EtablissementsPage() {
  const qc = useQueryClient()
  const { data, isLoading, error } = useQuery({ queryKey: ['etablissements', 'tous'], queryFn: () => fetchTousEtablissements() })

  const basculer = useMutation({
    mutationFn: ({ e, activer }) => (activer ? activerEtablissement(e.id) : desactiverEtablissement(e.id)),
    onSuccess: (_, { e, activer }) => {
      qc.invalidateQueries({ queryKey: ['etablissements'] })
      flash.succes(activer ? `L’établissement ${e.intitule} a été réactivé.` : `L’établissement ${e.intitule} a été désactivé.`)
    },
    onError: (err) => flash.erreur(messageApi(err)),
  })

  const colonnes = [
    { cle: 'code', titre: 'Code', className: 'font-semibold' },
    {
      cle: 'intitule', titre: 'Nom',
      rendu: (e) => (
        <Link to={`/admin/etablissements/${encodeURIComponent(e.code)}`} className="font-medium hover:underline" style={{ color: 'var(--brand-accent)' }}>
          {e.intitule}
        </Link>
      ),
    },
    { cle: 'societe_code', titre: 'Société' },
    { cle: 'ville', titre: 'Ville' },
    { cle: 'telephone', titre: 'Téléphone' },
    {
      cle: 'actif', titre: 'État', valeur: (e) => (!e.repris ? 'À reprendre' : e.actif ? 'Actif' : 'Inactif'),
      rendu: (e) => (e.repris ? <Pastille actif={e.actif} /> : <Pastille actif={false} non="À reprendre" />),
    },
    {
      cle: 'actions', titre: 'Actions', triable: false, className: 'w-32',
      rendu: (e) => (
        <Actions>
          <BoutonModifier
            to={`/admin/etablissements/${encodeURIComponent(e.code)}/modifier`}
            titre={e.repris ? 'Modifier' : 'Reprendre dans ECOPRIM'}
          />
          {e.repris && (e.actif ? (
            <BoutonSupprimer
              disabled={basculer.isPending}
              confirmation={`Supprimer l’établissement ${e.intitule} ? Il sera désactivé et pourra être réactivé.`}
              onConfirme={() => basculer.mutate({ e, activer: false })}
            />
          ) : (
            <button
              type="button" title="Réactiver" aria-label="Réactiver" disabled={basculer.isPending}
              onClick={() => basculer.mutate({ e, activer: true })}
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
      icon={Building}
      titre="Liste des Établissements"
      action={<BoutonEntete to="/admin/etablissements/nouveau" icon={PlusCircle}>Nouvel Établissement</BoutonEntete>}
    >
      <TableConsole
        colonnes={colonnes}
        lignes={data}
        cleLigne={(e) => e.code}
        chargement={isLoading}
        erreur={error}
        vide="Aucun établissement enregistré."
      />
    </CarteConsole>
  )
}
