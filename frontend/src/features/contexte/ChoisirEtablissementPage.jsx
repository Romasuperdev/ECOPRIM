import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Building2, Check, MapPin, Phone } from 'lucide-react'
import Button from '../../components/ui/Button'
import MessageErreur from '../../components/ui/MessageErreur'
import { messageErreurApi } from '../../lib/erreurs'
import { choisirEtablissement, fetchContexte, quitterEtablissement } from './contexteApi'

export default function ChoisirEtablissementPage() {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const { data, isLoading, error } = useQuery({ queryKey: ['contexte'], queryFn: fetchContexte })

  const invalider = () => qc.invalidateQueries({ queryKey: ['contexte'] })

  // Un refus s'affiche sur la page même (voir plus bas) : pas de message global en plus.
  const choisir = useMutation({
    mutationFn: choisirEtablissement,
    onSuccess: () => { invalider(); navigate('/') },
    meta: { erreurAffichee: true },
  })
  const quitter = useMutation({ mutationFn: quitterEtablissement, onSuccess: invalider, meta: { erreurAffichee: true } })
  // Le dernier échec seulement : chaque bouton remet l'autre action à zéro avant de partir.
  const echec = choisir.error ?? quitter.error

  if (isLoading) return <p className="text-muted">Chargement…</p>
  // Contexte illisible : le dire, plutôt qu'afficher « Aucun établissement disponible ».
  if (error) return <MessageErreur erreur={error} />

  const actuel = data?.etablissement_code
  const dispos = data?.disponibles ?? []

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Choisir un établissement</h1>
        <p className="mt-1 text-sm text-slate-500">
          Sélectionnez l’établissement sur lequel vous souhaitez travailler.
        </p>
      </div>

      {echec && (
        <div
          role="alert"
          className="mb-4 rounded-xl border px-4 py-3 text-sm font-medium"
          style={{ background: 'var(--danger-bg)', borderColor: 'var(--danger-border)', color: 'var(--danger-text)' }}
        >
          {choisir.error ? 'L’établissement n’a pas pu être sélectionné. ' : 'L’établissement n’a pas pu être désélectionné. '}
          {messageErreurApi(echec)}
        </div>
      )}

      {actuel && (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary-200 bg-primary-50 px-5 py-4">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-primary-700">
              Établissement actif
            </p>
            <p className="font-semibold text-slate-800">{data.etablissement_nom}</p>
            <p className="font-mono text-xs text-slate-500">{actuel}</p>
          </div>
          <Button variant="outline" disabled={quitter.isPending} onClick={() => { choisir.reset(); quitter.mutate() }}>
            Désélectionner
          </Button>
        </div>
      )}

      {dispos.length === 0 ? (
        <div className="rounded-xl border border-slate-200 bg-card py-16 text-center">
          <Building2 size={40} className="mx-auto mb-3 text-slate-300" />
          <p className="text-sm font-medium text-slate-500">Aucun établissement disponible</p>
          <p className="mt-1 text-xs text-muted">
            Vous n’êtes affecté à aucun établissement actif. Contactez un administrateur.
          </p>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {dispos.map((e) => {
            const estActif = e.code === actuel
            return (
              <div
                key={e.code}
                className={`relative rounded-xl border bg-card p-5 transition hover:shadow-md ${
                  estActif ? 'border-primary-500 ring-2 ring-primary-100' : 'border-slate-200'
                }`}
              >
                {estActif && (
                  <span className="absolute right-3 top-3 inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">
                    <Check size={12} /> Actif
                  </span>
                )}
                <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-primary-50 text-primary-700">
                  <Building2 size={18} />
                </div>
                <p className="font-semibold text-slate-800">{e.intitule}</p>
                <p className="mb-3 font-mono text-xs text-muted">{e.code}</p>
                {e.adresse && (
                  <p className="flex items-center gap-1.5 text-xs text-slate-500">
                    <MapPin size={12} /> {e.adresse}{e.ville ? `, ${e.ville}` : ''}
                  </p>
                )}
                {e.telephone && (
                  <p className="mt-1 flex items-center gap-1.5 text-xs text-slate-500">
                    <Phone size={12} /> {e.telephone}
                  </p>
                )}
                <div className="mt-4">
                  {estActif ? (
                    <Button className="w-full justify-center" onClick={() => navigate('/')}>
                      Accéder au tableau de bord
                    </Button>
                  ) : (
                    <Button
                      variant="outline"
                      className="w-full justify-center"
                      disabled={choisir.isPending}
                      onClick={() => { quitter.reset(); choisir.mutate(e.code) }}
                    >
                      Sélectionner
                    </Button>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
