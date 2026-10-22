import {
  BarChart3, BookOpen, CalendarDays, CircleHelp, ClipboardList, FileText, GraduationCap,
  KeyRound, Lightbulb, Megaphone, Printer, Rocket, School, Settings, ShieldCheck, Users,
} from 'lucide-react'

/**
 * Icônes proposées pour une catégorie d'Aide. Une liste fermée plutôt que « n'importe
 * quel nom lucide » : importer toute la bibliothèque pour en afficher une dizaine
 * alourdirait chaque page, et un nom mal saisi n'afficherait rien.
 */
export const ICONES_AIDE = {
  BookOpen, Rocket, School, Users, GraduationCap, ClipboardList, CalendarDays, BarChart3,
  FileText, Printer, Megaphone, Settings, ShieldCheck, KeyRound, Lightbulb, CircleHelp,
}

/** Icône d'une catégorie ; un nom inconnu (ou vide) retombe sur le livre ouvert. */
export const iconeAide = (nom) => ICONES_AIDE[nom] ?? BookOpen
