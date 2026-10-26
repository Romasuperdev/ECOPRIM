import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link2, List, X } from 'lucide-react'
import Select from '../../../components/ui/Select'
import { Actions, BoutonModifier, BoutonSupprimer, TableConsole, flash, messageApi } from './kit'
import { BoutonsFormulaire, CarteGrille, GrilleConsole } from './grille'
import {
  detacherApplicationSociete, fetchSocietesApplications, retirerApplicationsSociete, synchroniserApplicationsSociete,
} from '../plateformeApi'

const VIDE = { societe: '', applications: [] }

/**
 * Société - Applications — l'écran parcauto affectations-app : à gauche le formulaire
 * (une société, ses applications à cocher), à droite la liste de toutes les sociétés avec
 * leurs applications en pastilles.
 *
 * Choisir une société coche les applications qui lui sont déjà ouvertes ; « Enregistrer »
 * fait de la liste cochée SA liste — une application décochée lui est retirée. Le × d'une
 * pastille retire une seule application ; « Retirer tout », toutes. Ni la société ni
 * l'application ne sont touchées : seule la liaison disparaît.
 */
export default function SocietesApplicationsPage() {
  const qc = useQueryClient()
  const [form, setForm] = useState(VIDE)
  const [erreur, setErreur] = useState(null)

  const { data, isLoading, error } = useQuery({ queryKey: ['societes-applications'], queryFn: fetchSocietesApplications })
  const societes = data?.societes ?? []
  const applications = data?.applications ?? []

  const invalider = () => qc.invalidateQueries({ queryKey: ['societes-applications'] })

  /** Charge une société dans le formulaire, ses applications cochées. */
  const charger = (code) => {
    const societe = societes.find((s) => s.code === code)
    setErreur(null)
    setForm({ societe: code ?? '', applications: societe?.applications.map((a) => a.code) ?? [] })
  }

  const enregistrer = useMutation({
    mutationFn: (v) => synchroniserApplicationsSociete(v.societe, v.applications),
    onSuccess: (_, v) => {
      invalider()
      flash.succes(`Les applications de ${v.societe} ont été enregistrées.`)
      setForm(VIDE)
    },
    onError: (e) => setErreur(messageApi(e)),
  })

  const detacher = useMutation({
    mutationFn: ({ s, a }) => detacherApplicationSociete(s.code, a.code),
    onSuccess: (_, { s, a }) => {
      invalider()
      if (form.societe === s.code) setForm((f) => ({ ...f, applications: f.applications.filter((c) => c !== a.code) }))
      flash.succes(`${a.libelle} a été retirée de ${s.code}.`)
    },
    onError: (e) => flash.erreur(messageApi(e)),
  })

  const retirerTout = useMutation({
    mutationFn: (s) => retirerApplicationsSociete(s.code),
    onSuccess: (_, s) => {
      invalider()
      if (form.societe === s.code) setForm(VIDE)
      flash.succes(`Toutes les applications de ${s.code} ont été retirées.`)
    },
    onError: (e) => flash.erreur(messageApi(e)),
  })

  const cocher = (code, coche) => setForm((f) => ({
    ...f, applications: coche ? [...f.applications, code] : f.applications.filter((c) => c !== code),
  }))

  const soumettre = () => {
    const societe = societes.find((s) => s.code === form.societe)
    if (!societe) return
    if (form.applications.length === 0 && societe.applications.length > 0
      && !window.confirm(`Aucune application cochée : retirer toutes les applications de ${societe.code} ?`)) return
    enregistrer.mutate(form)
  }

  const colonnes = [
    { cle: 'code', titre: 'Code Société', className: 'font-semibold' },
    { cle: 'nom', titre: 'Société' },
    {
      cle: 'applications', titre: 'Applications', className: 'text-left',
      valeur: (s) => s.applications.map((a) => `${a.code} ${a.libelle}`).join(', '),
      rendu: (s) => (s.applications.length === 0 ? <span className="text-muted">Aucune</span> : (
        <div className="flex flex-wrap gap-1">
          {s.applications.map((a) => (
            <span
              key={a.code}
              className="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold"
              style={{ background: 'var(--info-bg)', color: 'var(--info-text)' }}
            >
              {a.code} <small className="text-muted">({a.libelle})</small>
              <button
                type="button" title="Retirer" aria-label={`Retirer ${a.libelle}`}
                disabled={detacher.isPending}
                onClick={() => { if (window.confirm(`Retirer ${a.libelle} de ${s.code} ?`)) detacher.mutate({ s, a }) }}
                className="ml-0.5 opacity-70 hover:opacity-100" style={{ color: 'var(--danger-text)' }}
              >
                <X size={11} />
              </button>
            </span>
          ))}
        </div>
      )),
    },
    {
      cle: 'actions', titre: 'Actions', triable: false, className: 'w-28',
      rendu: (s) => (
        <Actions>
          <BoutonModifier titre="Modifier" onClick={() => { charger(s.code); window.scrollTo({ top: 0, behavior: 'smooth' }) }} />
          <BoutonSupprimer
            titre="Retirer tout" disabled={s.applications.length === 0 || retirerTout.isPending}
            confirmation={`Retirer toutes les applications pour la société ${s.code} ?`}
            onConfirme={() => retirerTout.mutate(s)}
          />
        </Actions>
      ),
    },
  ]

  return (
    <GrilleConsole
      gauche={(
        <CarteGrille icon={Link2} titre="Affecter Applications à une Société">
          <form onSubmit={(e) => { e.preventDefault(); soumettre() }} noValidate className="space-y-4">
            <Select id="sa-societe" label="Société" value={form.societe} onChange={(e) => charger(e.target.value)}>
              <option value="">-- Choisir une société --</option>
              {societes.map((s) => <option key={s.code} value={s.code}>{s.code} - {s.nom}</option>)}
            </Select>

            <div>
              <p className="mb-1.5 block text-sm font-bold text-heading">Applications</p>
              <div className="max-h-72 space-y-0.5 overflow-y-auto rounded-xl border p-2" style={{ borderColor: 'var(--border)' }}>
                {applications.length === 0 && <p className="px-2 py-3 text-sm text-muted">Aucune application.</p>}
                {applications.map((a) => (
                  <label key={a.code} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-[var(--surface-2)]">
                    <input
                      type="checkbox" checked={form.applications.includes(a.code)} disabled={!form.societe}
                      onChange={(e) => cocher(a.code, e.target.checked)}
                    />
                    <span><span className="font-semibold">{a.code}</span> — {a.libelle}</span>
                  </label>
                ))}
              </div>
              <p className="mt-1.5 text-xs text-muted">
                Lorsque vous choisissez une société, les applications actuellement affectées sont
                automatiquement cochées ; une application décochée lui est retirée à l’enregistrement.
              </p>
            </div>

            {erreur && <p className="text-sm" style={{ color: 'var(--danger-text)' }}>{erreur}</p>}

            <BoutonsFormulaire
              onReinitialiser={() => { setForm(VIDE); setErreur(null) }}
              enCours={enregistrer.isPending}
              desactive={!form.societe}
            />
          </form>
        </CarteGrille>
      )}
      droite={(
        <CarteGrille icon={List} titre="Liste des Affectations">
          <TableConsole
            colonnes={colonnes}
            lignes={societes}
            cleLigne={(s) => s.code}
            chargement={isLoading}
            erreur={error}
            vide="Aucune société."
          />
        </CarteGrille>
      )}
    />
  )
}
