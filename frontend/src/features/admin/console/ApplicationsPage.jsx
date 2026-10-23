import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { LayoutGrid, PlusCircle } from 'lucide-react'
import { fetchApplications, supprimerApplication } from '../plateformeApi'
import {
  Actions, BoutonEntete, BoutonModifier, BoutonSupprimer, CarteConsole, TableConsole, flash, messageApi,
} from './kit'

/**
 * Applications de la suite — la liste parcauto (console/applications) : Code App,
 * Intitulé, et les boutons Modifier / Supprimer ; plus le nombre de comptes qui
 * travaillent sur chacune.
 *
 * Une application utilisée par une société ou par un compte ne se supprime pas : l'API
 * refuse et le message dit pourquoi.
 */
export default function ApplicationsPage() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['plateforme-applications'], queryFn: fetchApplications })

  const supprimer = useMutation({
    mutationFn: (a) => supprimerApplication(a.code),
    onSuccess: (_, a) => {
      qc.invalidateQueries({ queryKey: ['plateforme-applications'] })
      qc.invalidateQueries({ queryKey: ['societes-applications'] })
      flash.succes(`L’application ${a.libelle} a été supprimée.`)
    },
    onError: (err) => flash.erreur(messageApi(err)),
  })

  const colonnes = [
    { cle: 'code', titre: 'Code App', className: 'font-semibold' },
    { cle: 'libelle', titre: 'Intitulé' },
    { cle: 'comptes', titre: 'Comptes', valeur: (a) => a.comptes ?? 0, className: 'w-28' },
    {
      cle: 'actions', titre: 'Actions', triable: false, className: 'w-32',
      rendu: (a) => (
        <Actions>
          <BoutonModifier to={`/admin/applications/${encodeURIComponent(a.code)}/modifier`} />
          <BoutonSupprimer
            disabled={supprimer.isPending}
            confirmation={`Supprimer l’application ${a.libelle} ?`}
            onConfirme={() => supprimer.mutate(a)}
          />
        </Actions>
      ),
    },
  ]

  return (
    <CarteConsole
      icon={LayoutGrid}
      titre="Liste des Applications"
      action={<BoutonEntete to="/admin/applications/nouvelle" icon={PlusCircle}>Nouvelle Application</BoutonEntete>}
    >
      {data?.disponible === false ? (
        <p className="py-6 text-center text-sm font-medium text-muted">
          Le catalogue des applications n’existe pas sur cette installation : il n’y a rien à
          afficher, et ce n’est pas une panne.
        </p>
      ) : (
        <>
          <TableConsole
            colonnes={colonnes}
            lignes={data?.applications}
            cleLigne={(a) => a.code}
            chargement={isLoading}
            vide="Aucune application enregistrée."
          />
          {data?.codes_hors_catalogue?.length > 0 && (
            <p className="mt-3 text-sm font-medium" style={{ color: 'var(--warning-text)' }}>
              Des comptes portent un code d’application absent du catalogue :{' '}
              <strong>{data.codes_hors_catalogue.join(', ')}</strong>.
            </p>
          )}
        </>
      )}
    </CarteConsole>
  )
}
