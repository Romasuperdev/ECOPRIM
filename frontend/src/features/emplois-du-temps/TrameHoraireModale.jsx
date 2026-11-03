import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Clock, Plus, Trash2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import { RecapErreurs } from '../../components/ui/CarteFormulaire'
import { messageErreurApi } from '../../lib/erreurs'
import { enregistrerTrame } from './emploiApi'

const SEMAINE = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi']

/** « 08:00 » + 60 min → « 09:00 ». */
function ajouterMinutes(heure, minutes) {
  const [h, m] = (heure || '07:30').split(':').map(Number)
  const total = Math.min(h * 60 + m + minutes, 23 * 60 + 59)
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}

/** Les valeurs d'ECONOMAT peuvent être « 7:30 » : le champ horaire veut « 07:30 ». */
function normaliser(heure) {
  const m = /^(\d{1,2})[:hH](\d{2})/.exec(String(heure ?? '').trim())
  return m ? `${m[1].padStart(2, '0')}:${m[2]}` : ''
}

/**
 * Saisie de la trame des emplois du temps : jours de cours et plages horaires.
 * Tout part en un seul enregistrement ; une ligne qui porte déjà des cours est refusée au
 * retrait, avec le motif.
 */
export default function TrameHoraireModale({ jours: joursInitiaux, heures: heuresInitiales, onFermer }) {
  const qc = useQueryClient()
  const [jours, setJours] = useState(() => joursInitiaux.map((j) => ({ code: j.code, libelle: j.libelle ?? '' })))
  const [heures, setHeures] = useState(() =>
    heuresInitiales.map((h) => ({ code: h.code, debut: normaliser(h.debut), fin: normaliser(h.fin) })))
  const [erreur, setErreur] = useState(null)

  const enregistrer = useMutation({
    mutationFn: () => enregistrerTrame({
      jours: jours.filter((j) => j.libelle.trim()).map((j) => ({ code: j.code ?? null, libelle: j.libelle.trim() })),
      heures: heures.map((h) => ({ code: h.code ?? null, debut: h.debut, fin: h.fin })),
    }),
    onSuccess: (referentiels) => {
      qc.setQueryData(['emploi-referentiels'], referentiels)
      qc.invalidateQueries({ queryKey: ['emploi'] })
      onFermer()
    },
    onError: (e) => setErreur(messageErreurApi(e, 'La trame n’a pas pu être enregistrée.')),
  })

  const libellesPris = new Set(jours.map((j) => j.libelle.trim().toLowerCase()))
  const semaineManquante = SEMAINE.filter((j) => !libellesPris.has(j.toLowerCase()))

  const majJour = (i, libelle) => setJours((js) => js.map((j, k) => (k === i ? { ...j, libelle } : j)))
  const majHeure = (i, champ, valeur) => setHeures((hs) => hs.map((h, k) => (k === i ? { ...h, [champ]: valeur } : h)))

  const ajouterPlage = () => setHeures((hs) => {
    const derniere = hs[hs.length - 1]
    const debut = derniere?.fin || '07:30'
    return [...hs, { code: null, debut, fin: ajouterMinutes(debut, 60) }]
  })

  const incomplet = heures.some((h) => !h.debut || !h.fin)

  return (
    <ModaleFormulaire
      titrePage="Emplois du temps"
      titre="Trame horaire"
      sousTitre="Jours de cours et plages horaires de la grille"
      icon={Clock}
      large
      onFermer={onFermer}
      onValider={() => { setErreur(null); enregistrer.mutate() }}
      enCours={enregistrer.isPending}
      valideDesactive={incomplet}
      titreValider={incomplet ? 'Chaque plage doit avoir une heure de début et de fin.' : undefined}
      entreeValide={false}
    >
      <RecapErreurs erreurs={[erreur]} />

      <section className="mb-6">
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-semibold text-heading">Jours de cours</h3>
          <div className="flex flex-wrap gap-2">
            {semaineManquante.length > 0 && (
              <Button type="button" variant="outline" className="!px-3 !py-1.5 text-xs"
                      onClick={() => setJours((js) => [...js, ...semaineManquante.map((libelle) => ({ code: null, libelle }))])}>
                <Plus size={14} className="mr-1 inline" /> Lundi → Vendredi
              </Button>
            )}
            <Button type="button" variant="outline" className="!px-3 !py-1.5 text-xs"
                    onClick={() => setJours((js) => [...js, { code: null, libelle: '' }])}>
              <Plus size={14} className="mr-1 inline" /> Un jour
            </Button>
          </div>
        </div>

        {jours.length === 0 && <p className="text-sm text-muted">Aucun jour : ajoutez la semaine de cours.</p>}
        <div className="grid gap-2 sm:grid-cols-2">
          {jours.map((j, i) => (
            <div key={j.code ?? `n${i}`} className="flex items-end gap-2">
              <div className="flex-1">
                <Input id={`trame-jour-${i}`} label={`Jour ${i + 1}`} value={j.libelle} maxLength={30}
                       onChange={(e) => majJour(i, e.target.value)} placeholder="Ex. Lundi" />
              </div>
              <Button type="button" variant="outline" className="!px-2.5" title="Retirer ce jour"
                      onClick={() => setJours((js) => js.filter((_, k) => k !== i))}>
                <Trash2 size={15} />
              </Button>
            </div>
          ))}
        </div>
      </section>

      <section>
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-semibold text-heading">Plages horaires</h3>
          <Button type="button" variant="outline" className="!px-3 !py-1.5 text-xs" onClick={ajouterPlage}>
            <Plus size={14} className="mr-1 inline" /> Une plage
          </Button>
        </div>

        {heures.length === 0 && (
          <p className="text-sm text-muted">Aucune plage : ajoutez-en une, la suivante démarre à la fin de la précédente.</p>
        )}
        <div className="flex flex-col gap-2">
          {heures.map((h, i) => (
            <div key={h.code ?? `n${i}`} className="flex flex-wrap items-end gap-2">
              <div className="w-36">
                <Input id={`trame-debut-${i}`} type="time" label="Début" value={h.debut}
                       onChange={(e) => majHeure(i, 'debut', e.target.value)} />
              </div>
              <div className="w-36">
                <Input id={`trame-fin-${i}`} type="time" label="Fin" value={h.fin}
                       onChange={(e) => majHeure(i, 'fin', e.target.value)} />
              </div>
              <Button type="button" variant="outline" className="!px-2.5" title="Retirer cette plage"
                      onClick={() => setHeures((hs) => hs.filter((_, k) => k !== i))}>
                <Trash2 size={15} />
              </Button>
            </div>
          ))}
        </div>
      </section>

      <p className="mt-4 text-xs text-muted">
        La trame est commune à tous les établissements. Un jour ou une plage qui porte déjà des
        cours ne peut pas être retiré : videz d’abord ces cases dans la grille.
      </p>
    </ModaleFormulaire>
  )
}
